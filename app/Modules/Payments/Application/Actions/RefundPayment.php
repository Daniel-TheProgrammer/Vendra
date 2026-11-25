<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\RefundSettlement;
use App\Modules\Payments\Domain\PaymentAccountMissing;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Domain\RefundNotAllowed;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Money;

/**
 * Gives back part or all of a captured payment.
 *
 * What is left to refund is computed under the payment's row lock, counting
 * pending refunds too, so two concurrent refunds can never return more than
 * was paid (a database trigger holds the same line). The provider call is
 * keyed on our refund id, so a retry finds the refund the first attempt made.
 */
final readonly class RefundPayment
{
    public function __construct(
        private TransactionRunner $transaction,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private RefundSettlement $settlement,
        private Clock $clock,
    ) {}

    /**
     * @param  Money|null  $amount  null for everything not yet refunded
     *
     * @throws EntityNotFound
     * @throws RefundNotAllowed
     * @throws PaymentAccountMissing
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected
     */
    public function __invoke(string $intentId, ?Money $amount = null, ?string $reason = null, ?string $actorId = null): Refund
    {
        $account = $this->accounts->current();
        [$intent, $refund] = $this->transaction->run(fn (): array => $this->open($intentId, $amount, $reason, $actorId));

        try {
            $remote = $this->gateway->refund($account, (string) $intent->gateway_ref, $refund->amount(), $refund->id, 'refund:'.$refund->id);
        } catch (PaymentGatewayUnavailable|GatewayRejected $failure) {
            $refund->moveTo(RefundStatus::Failed, $this->clock->now());
            $refund->failure_code = 'gateway_error';
            $refund->save();

            throw $failure;
        }

        $refund->gateway_ref = $remote->reference;
        $refund->save();

        return $this->settlement->apply($refund->id, $remote->status, $remote->failureCode);
    }

    /**
     * @return array{PaymentIntent, Refund}
     */
    private function open(string $intentId, ?Money $amount, ?string $reason, ?string $actorId): array
    {
        $intent = PaymentIntent::query()->whereKey($intentId)->lockForUpdate()->first() ?? throw EntityNotFound::withId('Payment intent', $intentId);

        if ($intent->status !== PaymentIntentStatus::Succeeded) {
            throw RefundNotAllowed::notCaptured($intent->id, $intent->status);
        }

        $refundable = $intent->amount()->subtract(Money::of((int) Refund::query()
            ->where('payment_intent_id', $intent->id)
            ->whereIn('status', [RefundStatus::Pending, RefundStatus::Succeeded])
            ->sum('amount_minor'), $intent->currency));
        $amount ??= $refundable;

        if (! $amount->isPositive() || $amount->greaterThan($refundable)) {
            throw RefundNotAllowed::exceeds($intent->id, $amount, $refundable);
        }

        $refund = Refund::query()->create([
            'payment_intent_id' => $intent->id,
            'gateway' => $intent->gateway,
            'amount_minor' => $amount->amount,
            'currency' => $intent->currency,
            'status' => RefundStatus::Pending,
            'reason' => $reason,
            'requested_by' => $actorId,
        ]);

        return [$intent, $refund];
    }
}
