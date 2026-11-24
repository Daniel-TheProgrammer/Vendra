<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ShipStock;
use App\Modules\Orders\Application\Services\OrderHistory;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Domain\Events\OrderFulfilled;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Ships a paid order: its reserved units leave stock, in the same
 * transaction as the status change.
 */
final readonly class FulfilOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private ShipStock $ship,
        private OrderHistory $history,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $trackingReference = null, ?string $actorId = null): Order
    {
        $order = $this->transaction->run(function () use ($orderId, $trackingReference, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $from = $order->moveTo(OrderStatus::Fulfilled, $this->clock->now());

            ($this->ship)(
                $order->stockLines(),
                Order::stockReference($order->id),
            );

            $order->save();
            $this->history->record($order, 'fulfilled', $from, $actorId, array_filter(['tracking_reference' => $trackingReference]));

            return $order;
        });

        $this->events->dispatch(new OrderFulfilled($order->tenant_id, $order->id, $order->version, $this->clock->now()));

        return $order;
    }
}
