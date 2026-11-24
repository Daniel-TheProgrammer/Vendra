<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Application\Services\OrderTransitions;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * pending → awaiting_payment: a payment for the order has been started
 * (Payments module, Phase 6). The stock stays reserved.
 */
final readonly class RequestPayment
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderTransitions $transitions,
    ) {}

    /**
     * @throws IllegalTransition
     */
    public function __invoke(string $orderId, ?string $paymentReference = null, ?string $actorId = null): Order
    {
        return $this->transaction->run(function () use ($orderId, $paymentReference, $actorId): Order {
            $order = $this->lock->acquire($orderId);
            $this->transitions->apply($order, OrderStatus::AwaitingPayment, $actorId, ['payment_reference' => $paymentReference]);

            return $order;
        });
    }
}
