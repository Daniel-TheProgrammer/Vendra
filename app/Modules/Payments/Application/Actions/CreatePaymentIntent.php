<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Orders\Application\Actions\RequestPayment;
use App\Modules\Orders\Application\Queries\FindOrderSummary;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\OrderNotPayable;
use App\Modules\Payments\Domain\PaymentAccountMissing;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentOpen;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Starts collecting an order's total: the order moves to awaiting_payment
 * and the provider opens an intent the customer confirms in their browser
 * with the returned client secret.
 *
 * The provider call is keyed on our intent id, so a retried call finds the
 * intent the first one created. If the provider cannot be reached the
 * intent is marked failed, which frees the order for the next attempt.
 *
 * Over HTTP the idempotency middleware's transaction surrounds all of this
 * (ADR 0007): a 503 rolls the whole attempt back, order status included,
 * and the retry starts afresh. A provider intent orphaned that way is
 * harmless, because its client secret never reached the customer.
 */
final readonly class CreatePaymentIntent
{
    public function __construct(
        private TransactionRunner $transaction,
        private FindOrderSummary $orders,
        private RequestPayment $requestPayment,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private Clock $clock,
    ) {}

    /**
     * @throws EntityNotFound
     * @throws PaymentAccountMissing
     * @throws OrderNotPayable
     * @throws PaymentIntentOpen
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected
     */
    public function __invoke(string $orderId, ?string $actorId = null): StartedPayment
    {
        $account = $this->accounts->current();
        $intent = $this->transaction->run(fn (): PaymentIntent => $this->open($orderId, $actorId));

        try {
            $remote = $this->gateway->createIntent($account, $intent->amount(), $intent->id, 'intent:'.$intent->id);
        } catch (PaymentGatewayUnavailable|GatewayRejected $failure) {
            $intent->moveTo(PaymentIntentStatus::Failed, $this->clock->now());
            $intent->failure_code = 'gateway_error';
            $intent->failure_message = mb_substr($failure->getMessage(), 0, 500);
            $intent->save();

            throw $failure;
        }

        $intent->gateway_ref = $remote->reference;
        $intent->save();

        return new StartedPayment($intent, $remote->clientSecret);
    }

    private function open(string $orderId, ?string $actorId): PaymentIntent
    {
        $order = ($this->orders)($orderId);

        if (! $order->total->isPositive()) {
            throw OrderNotPayable::free($orderId);
        }

        match ($order->status) {
            // Locks the order row: concurrent attempts queue here.
            OrderStatus::Pending => ($this->requestPayment)($orderId, actorId: $actorId),
            OrderStatus::AwaitingPayment => null,
            default => throw OrderNotPayable::inStatus($orderId, $order->status->value),
        };

        $this->refuseIfOpen($orderId);

        try {
            // Own savepoint: the partial unique index on open intents catches
            // a concurrent attempt that passed the check above.
            return $this->transaction->run(fn (): PaymentIntent => PaymentIntent::query()->create([
                'order_id' => $orderId,
                'gateway' => $this->gateway->name(),
                'amount_minor' => $order->total->amount,
                'currency' => $order->total->currency,
                'status' => PaymentIntentStatus::RequiresPayment,
                'created_by' => $actorId,
            ]));
        } catch (UniqueConstraintViolationException) {
            $this->refuseIfOpen($orderId);

            throw OrderNotPayable::inStatus($orderId, 'already paid');
        }
    }

    /**
     * @throws PaymentIntentOpen
     */
    private function refuseIfOpen(string $orderId): void
    {
        $open = PaymentIntent::query()
            ->where('order_id', $orderId)
            ->whereIn('status', PaymentIntentStatus::openValues())
            ->value('id');

        if (is_string($open)) {
            throw PaymentIntentOpen::forOrder($orderId, $open);
        }
    }
}
