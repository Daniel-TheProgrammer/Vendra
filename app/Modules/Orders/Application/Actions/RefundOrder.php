<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ReleaseStock;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Application\Services\OrderTransitions;
use App\Modules\Orders\Domain\Events\OrderRefunded;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * paid or fulfilled → refunded (Payments module, Phase 6).
 *
 * Refunding a paid order that has not shipped gives its reserved stock
 * back. A fulfilled order's units have left the warehouse: if they come
 * back, they are received with a "returned" stock adjustment, not here.
 */
final readonly class RefundOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
        private ReleaseStock $release,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $refundReference = null, ?string $actorId = null): Order
    {
        $order = $this->transaction->run(function () use ($orderId, $refundReference, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $heldStock = $order->status->holdsStock();

            $this->transitions->apply($order, OrderStatus::Refunded, $actorId, ['refund_reference' => $refundReference]);

            if ($heldStock) {
                ($this->release)($order->stockLines(), Order::stockReference($order->id));
            }

            return $order;
        });

        $this->events->dispatch(new OrderRefunded($order->tenant_id, $order->id, $order->version, $this->clock->now()));

        return $order;
    }
}
