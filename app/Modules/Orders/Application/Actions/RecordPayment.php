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
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * awaiting_payment → paid: the payment succeeded (Payments module, Phase 6).
 * The ledger books the sale from OrderPaid.
 */
final readonly class RecordPayment
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $paymentReference = null, ?string $actorId = null): Order
    {
        $order = $this->transaction->run(function () use ($orderId, $paymentReference, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $this->transitions->apply($order, OrderStatus::Paid, $actorId, ['payment_reference' => $paymentReference]);

            return $order;
        });

        $this->events->dispatch(new OrderPaid($order->tenant_id, $order->id, $order->version, $this->clock->now()));

        return $order;
    }
}
