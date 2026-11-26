<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Orders\Application\Actions\RecordPayment;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Payments\Domain\Events\PaymentCaptured;
use App\Modules\Payments\Domain\Events\PaymentFailed;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Domain\Settlement;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;

/**
 * The one place a payment intent changes status, whoever brings the news:
 * the response to our own gateway call, a webhook, or the order being
 * cancelled. Applying the same news twice, or news older than what the
 * intent already knows, changes nothing.
 *
 * A capture pays the order in the same transaction. If the order can no
 * longer be paid (it was cancelled while the customer was paying), the
 * money is kept as a customer credit until it is refunded.
 */
final readonly class PaymentSettlement
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
        private RecordPayment $recordPayment,
        private EventRecorder $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws EntityNotFound
     */
    public function apply(string $intentId, PaymentUpdate $update): PaymentIntent
    {
        return $this->transaction->run(function () use ($intentId, $update): PaymentIntent {
            $intent = PaymentIntent::query()->whereKey($intentId)->lockForUpdate()->first() ?? throw EntityNotFound::withId('Payment intent', $intentId);

            if (! $this->isNews($intent, $update)) {
                return $intent;
            }

            $now = $this->clock->now();
            $intent->moveTo($update->status, $now);
            $intent->gateway_updated_at = $update->reportedAt ?? $intent->gateway_updated_at;

            if ($update->status === PaymentIntentStatus::Succeeded) {
                $fee = $update->fee ?? Money::zero($intent->currency);
                $intent->fee_minor = $fee->amount;
                $intent->settlement = $this->payOrder($intent);
                $this->events->record(new PaymentCaptured($intent->tenant_id, $intent->id, $intent->order_id, $intent->amount(), $fee, $intent->settlement, $now));
            }

            if ($update->status === PaymentIntentStatus::Failed) {
                $intent->failure_code = $update->failureCode;
                $intent->failure_message = $update->failureMessage !== null ? mb_substr($update->failureMessage, 0, 500) : null;
                $this->events->record(new PaymentFailed($intent->tenant_id, $intent->id, $intent->order_id, $update->failureCode, $now));
            }

            $intent->save();

            return $intent;
        });
    }

    /**
     * Stale or repeated news is dropped: a report older than the last one
     * applied, the status the intent already has, or a move its state
     * machine does not allow (a late "processing" after "succeeded").
     */
    private function isNews(PaymentIntent $intent, PaymentUpdate $update): bool
    {
        if ($update->reportedAt instanceof CarbonImmutable && $intent->gateway_updated_at !== null && $update->reportedAt->lessThan($intent->gateway_updated_at)) {
            $this->logger->info('Ignored a payment update older than the intent\'s last one.', [
                'payment_intent_id' => $intent->id,
                'status' => $update->status->value,
                'reported_at' => $update->reportedAt->toIso8601String(),
                'known_at' => $intent->gateway_updated_at->toIso8601String(),
            ]);

            return false;
        }

        if ($intent->status === $update->status) {
            return false;
        }

        if (! $intent->status->canTransitionTo($update->status)) {
            // A capture we refuse to record is money nobody accounts for:
            // payments:reconcile reports it, and so does this.
            $level = $update->status === PaymentIntentStatus::Succeeded ? 'critical' : 'info';
            $this->logger->log($level, 'Ignored a payment update its state machine does not allow.', [
                'payment_intent_id' => $intent->id,
                'from' => $intent->status->value,
                'to' => $update->status->value,
            ]);

            return false;
        }

        return true;
    }

    private function payOrder(PaymentIntent $intent): Settlement
    {
        try {
            ($this->recordPayment)($intent->order_id, $intent->id);

            return Settlement::Order;
        } catch (IllegalTransition $refused) {
            $this->logger->warning('Captured a payment for an order that can no longer be paid; the money is held as customer credit until refunded.', [
                'payment_intent_id' => $intent->id,
                'order_id' => $intent->order_id,
                'reason' => $refused->getMessage(),
            ]);

            return Settlement::CustomerCredit;
        }
    }
}
