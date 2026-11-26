<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Application\Services\OrderTransitions;
use App\Modules\Orders\Domain\Events\OrderPaid;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * awaiting_payment → paid: the payment succeeded (Payments module, Phase 6).
 * The money itself is booked from the payment's own event (PaymentCaptured).
 */
final readonly class RecordPayment
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $paymentReference = null, ?string $actorId = null): Order
    {
        return $this->transaction->run(function () use ($orderId, $paymentReference, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $this->transitions->apply($order, OrderStatus::Paid, $actorId, ['payment_reference' => $paymentReference]);
            $this->events->record(new OrderPaid($order->tenant_id, $order->id, $order->version, $this->clock->now()));

            return $order;
        });
    }
}
