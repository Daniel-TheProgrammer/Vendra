<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ReleaseStock;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Application\Services\OrderTransitions;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\VersionMismatch;

/**
 * Cancels an unpaid order and gives its reserved stock back, in one
 * transaction. A paid order is refunded instead.
 */
final readonly class CancelOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
        private ReleaseStock $release,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    /**
     * @throws IllegalTransition
     * @throws VersionMismatch
     */
    public function __invoke(string $orderId, ?string $reason = null, ?string $actorId = null, ?int $expectedVersion = null): Order
    {
        return $this->transaction->run(function () use ($orderId, $reason, $actorId, $expectedVersion): Order {
            $order = $this->lock->acquire($orderId, $expectedVersion);
            $this->transitions->apply($order, OrderStatus::Cancelled, $actorId, ['reason' => $reason]);
            ($this->release)($order->stockLines(), Order::stockReference($order->id));
            $this->events->record(new OrderCancelled($order->tenant_id, $order->id, $order->version, $this->clock->now()));

            return $order;
        });
    }
}
