<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Webhooks;

use App\Modules\Payments\Application\Actions\RecordDispute;
use App\Modules\Payments\Application\Actions\SyncRefunds;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\PaymentSettlement;
use App\Modules\Payments\Application\Services\PaymentUpdate;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeObjects;
use App\Modules\Payments\Infrastructure\Models\PaymentEventRecord;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a stored Stripe event into domain actions, in the current tenant.
 *
 *   payment_intent.amount_capturable_updated, .processing  → processing
 *   payment_intent.succeeded                               → succeeded (pays the order, books the money)
 *   payment_intent.payment_failed                          → failed
 *   payment_intent.canceled                                → cancelled
 *   charge.dispute.created                                 → the payment is marked disputed
 *   charge.refunded, charge.refund.updated                 → refunds synced with the provider's list
 *   anything else                                          → stored, marked ignored
 *
 * Every status change goes through PaymentSettlement with the event's own
 * "created" time, so an event delivered late never moves an intent back.
 * Processing an event twice is harmless; an event about an intent we do
 * not know is "unmatched", recorded, and not retried.
 */
final readonly class StripeEventProcessor
{
    private const INTENT_EVENTS = [
        'payment_intent.amount_capturable_updated',
        'payment_intent.processing',
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
    ];

    /** About the charge (data.object), which names its payment intent. */
    private const REFUND_EVENTS = [
        'charge.refunded',
        'charge.refund.updated',
    ];

    public function __construct(
        private PaymentSettlement $settlement,
        private RecordDispute $recordDispute,
        private SyncRefunds $syncRefunds,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(string $eventId): void
    {
        $event = PaymentEventRecord::query()->whereKey($eventId)->first();

        if (! $event instanceof PaymentEventRecord || $event->processed_at !== null) {
            return;
        }

        $event->attempts++;
        $event->save();

        try {
            $outcome = match (true) {
                in_array($event->type, self::INTENT_EVENTS, true) => $this->intentChanged($event),
                $event->type === 'charge.dispute.created' => $this->disputeCreated($event),
                in_array($event->type, self::REFUND_EVENTS, true) => $this->refundsChanged($event),
                default => PaymentEventRecord::OUTCOME_IGNORED,
            };
        } catch (Throwable $failure) {
            $event->last_error = mb_substr($failure::class.': '.$failure->getMessage(), 0, 2000);
            $event->save();

            throw $failure;
        }

        $event->processed_at = $this->clock->now();
        $event->outcome = $outcome;
        $event->last_error = null;
        $event->save();
    }

    private function intentChanged(PaymentEventRecord $event): string
    {
        $object = $event->object();
        $intent = $this->findIntent($object);

        if (! $intent instanceof PaymentIntent) {
            return $this->unmatched($event, $object['id'] ?? null);
        }

        $remote = StripeObjects::paymentIntent($object);
        $fee = $remote->fee;

        // The event's copy of the intent does not expand the charge; the fee
        // is read from Stripe when the money has moved.
        if ($remote->status === PaymentIntentStatus::Succeeded && ! $fee instanceof Money && $intent->gateway_ref !== null) {
            $fee = $this->gateway->retrieve($this->accounts->current(), $intent->gateway_ref)->fee;
        }

        $before = $intent->version;
        $after = $this->settlement->apply($intent->id, new PaymentUpdate(
            status: $remote->status,
            reportedAt: $event->gateway_created_at,
            fee: $fee,
            failureCode: $remote->failureCode,
            failureMessage: $remote->failureMessage,
        ));

        return $after->version !== $before ? PaymentEventRecord::OUTCOME_APPLIED : PaymentEventRecord::OUTCOME_IGNORED;
    }

    private function disputeCreated(PaymentEventRecord $event): string
    {
        $dispute = $event->object();
        $intent = $this->findIntent(['id' => $dispute['payment_intent'] ?? null]);

        if (! $intent instanceof PaymentIntent) {
            return $this->unmatched($event, $dispute['payment_intent'] ?? null);
        }

        $currency = is_string($dispute['currency'] ?? null) ? Currency::tryFrom(strtoupper($dispute['currency'])) : null;
        $amount = is_int($dispute['amount'] ?? null) && $currency instanceof Currency ? Money::of($dispute['amount'], $currency) : $intent->amount();
        $reason = is_string($dispute['reason'] ?? null) ? $dispute['reason'] : null;

        $this->logger->warning('A payment was disputed.', ['payment_intent_id' => $intent->id, 'order_id' => $intent->order_id, 'reason' => $reason]);

        return ($this->recordDispute)($intent->id, $amount, $reason, $event->gateway_created_at)
            ? PaymentEventRecord::OUTCOME_APPLIED
            : PaymentEventRecord::OUTCOME_IGNORED;
    }

    /**
     * The event says something about the charge's refunds; the provider's
     * list of them is the truth to sync with (it also brings refunds made
     * in the dashboard, which Vendra has never seen).
     */
    private function refundsChanged(PaymentEventRecord $event): string
    {
        $charge = $event->object();
        $intent = $this->findIntent(['id' => $charge['payment_intent'] ?? null]);

        if (! $intent instanceof PaymentIntent) {
            return $this->unmatched($event, $charge['payment_intent'] ?? null);
        }

        return ($this->syncRefunds)($intent) > 0 ? PaymentEventRecord::OUTCOME_APPLIED : PaymentEventRecord::OUTCOME_IGNORED;
    }

    /**
     * By the provider's id; failing that by our id in the metadata, for an
     * intent whose creation response we never stored.
     *
     * @param  array<string, mixed>  $object
     */
    private function findIntent(array $object): ?PaymentIntent
    {
        $ref = $object['id'] ?? null;

        if (! is_string($ref) || $ref === '') {
            return null;
        }

        $intent = PaymentIntent::query()->where('gateway_ref', $ref)->first();

        if ($intent instanceof PaymentIntent) {
            return $intent;
        }

        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $ours = $metadata['vendra_payment_intent_id'] ?? null;

        if (! is_string($ours) || ! PaymentIntent::query()->whereKey($ours)->whereNull('gateway_ref')->exists()) {
            return null;
        }

        PaymentIntent::query()->whereKey($ours)->whereNull('gateway_ref')->update(['gateway_ref' => $ref]);

        return PaymentIntent::query()->whereKey($ours)->first();
    }

    private function unmatched(PaymentEventRecord $event, mixed $ref): string
    {
        $this->logger->warning('A payment event names an intent Vendra does not know.', [
            'payment_event_id' => $event->id,
            'type' => $event->type,
            'gateway_ref' => is_string($ref) ? $ref : null,
        ]);

        return PaymentEventRecord::OUTCOME_UNMATCHED;
    }
}
