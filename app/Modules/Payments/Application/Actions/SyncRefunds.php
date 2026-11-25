<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Gateway\GatewayRefund;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\RefundSettlement;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Brings a payment's refunds in line with the provider: pending refunds
 * that settled, and refunds made outside Vendra (in the provider's
 * dashboard), which are recorded here and booked like any other.
 */
final readonly class SyncRefunds
{
    public const EXTERNAL_REASON = 'Refunded at the payment provider';

    public function __construct(
        private TransactionRunner $transaction,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private RefundSettlement $settlement,
    ) {}

    /**
     * @return int how many refunds changed
     */
    public function __invoke(PaymentIntent $intent): int
    {
        if ($intent->gateway_ref === null) {
            return 0;
        }

        $changed = 0;

        foreach ($this->gateway->refunds($this->accounts->current(), $intent->gateway_ref) as $remote) {
            $local = $this->local($intent, $remote);
            $before = $local->version;
            $after = $this->settlement->apply($local->id, $remote->status, $remote->failureCode);
            $changed += $after->version !== $before || $local->wasRecentlyCreated ? 1 : 0;
            // (A refund first learnt of here counts even while still pending.)
        }

        return $changed;
    }

    private function local(PaymentIntent $intent, GatewayRefund $remote): Refund
    {
        $known = Refund::query()->where('gateway', $intent->gateway)->where('gateway_ref', $remote->reference)->first()
            ?? ($remote->ourId !== null ? Refund::query()->whereKey($remote->ourId)->where('payment_intent_id', $intent->id)->first() : null);

        if ($known instanceof Refund) {
            if ($known->gateway_ref === null) {
                $known->gateway_ref = $remote->reference;
                $known->save();
            }

            return $known;
        }

        return $this->transaction->run(static function () use ($intent, $remote): Refund {
            PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->first();

            return Refund::query()->create([
                'payment_intent_id' => $intent->id,
                'gateway' => $intent->gateway,
                'gateway_ref' => $remote->reference,
                'amount_minor' => $remote->amount->amount,
                'currency' => $intent->currency,
                'status' => RefundStatus::Pending,
                'reason' => self::EXTERNAL_REASON,
            ]);
        });
    }
}
