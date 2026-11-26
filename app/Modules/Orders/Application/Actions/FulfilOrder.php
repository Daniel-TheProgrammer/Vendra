<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ShipStock;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Application\Services\OrderTransitions;
use App\Modules\Orders\Domain\Events\OrderFulfilled;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\VersionMismatch;

/**
 * Ships a paid order: its reserved units leave stock, in the same
 * transaction as the status change.
 */
final readonly class FulfilOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
        private ShipStock $ship,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    /**
     * @throws IllegalTransition
     * @throws VersionMismatch
     */
    public function __invoke(string $orderId, ?string $trackingReference = null, ?string $actorId = null, ?int $expectedVersion = null): Order
    {
        return $this->transaction->run(function () use ($orderId, $trackingReference, $actorId, $expectedVersion): Order {
            $order = $this->lock->acquire($orderId, $expectedVersion);
            $this->transitions->apply($order, OrderStatus::Fulfilled, $actorId, ['tracking_reference' => $trackingReference]);
            ($this->ship)($order->stockLines(), Order::stockReference($order->id));
            $this->events->record(new OrderFulfilled($order->tenant_id, $order->id, $order->version, $this->clock->now()));

            return $order;
        });
    }
}
