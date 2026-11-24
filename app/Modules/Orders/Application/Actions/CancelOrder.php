<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ReleaseStock;
use App\Modules\Orders\Application\Services\OrderHistory;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Cancels an unpaid order and gives its reserved stock back, in one
 * transaction. Paid orders are refunded instead (Phase 6).
 */
final readonly class CancelOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private ReleaseStock $release,
        private OrderHistory $history,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $reason = null, ?string $actorId = null): Order
    {
        $order = $this->transaction->run(function () use ($orderId, $reason, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $from = $order->moveTo(OrderStatus::Cancelled, $this->clock->now());

            ($this->release)(
                $order->stockLines(),
                Order::stockReference($order->id),
            );

            $order->save();
            $this->history->record($order, 'cancelled', $from, $actorId, array_filter(['reason' => $reason]));

            return $order;
        });

        $this->events->dispatch(new OrderCancelled($order->tenant_id, $order->id, $order->version, $this->clock->now()));

        return $order;
    }
}
