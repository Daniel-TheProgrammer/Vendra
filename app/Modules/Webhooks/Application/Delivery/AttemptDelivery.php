<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Webhooks\Application\Security\DestinationGuard;
use App\Modules\Webhooks\Application\Services\WebhookSignature;
use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Domain\PauseReason;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Domain\UnsafeWebhookUrl;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * One attempt at a delivery, in the current tenant.
 *
 *  1. Claim it: a lease on next_attempt_at, taken only if the delivery is
 *     due, so a queued job and the sweeper never send it twice at once.
 *  2. Re-check the URL with the SSRF guard and connect only to the
 *     addresses it approved.
 *  3. Sign, send, and record the outcome under the subscription's row lock:
 *     2xx succeeds and clears the subscription's failure streak; anything
 *     else schedules the next retry (webhooks.retry_schedule) or, after
 *     the last one, marks the delivery dead. Too many consecutive failures
 *     pause the subscription and tell the tenant's owners.
 *
 * No network call runs inside a transaction.
 */
final readonly class AttemptDelivery
{
    /** Extra seconds the lease outlives the request timeout. */
    private const LEASE_MARGIN = 30;

    public function __construct(
        private TransactionRunner $transaction,
        private DestinationGuard $guard,
        private WebhookTransport $transport,
        private SubscriptionAlerts $alerts,
        private Clock $clock,
        private Config $config,
    ) {}

    public function __invoke(string $deliveryId): ?WebhookDelivery
    {
        $now = $this->clock->now();
        $timeout = $this->config->integer('webhooks.timeout', 5);

        if (! $this->claim($deliveryId, $now, $timeout)) {
            return null;
        }

        $delivery = WebhookDelivery::query()->with('subscription')->whereKey($deliveryId)->firstOrFail();
        $subscription = $delivery->subscription;

        if (! $subscription instanceof WebhookSubscription || ($subscription->status === SubscriptionStatus::Paused && ! $delivery->isTest())) {
            // Waits for the subscription to be resumed.
            $this->release($deliveryId, $now);

            return $delivery;
        }

        try {
            $destination = $this->guard->check($subscription->url);
            $timestamp = $now->getTimestamp();
            $result = $this->transport->send($destination, $delivery->body, [
                'User-Agent' => 'Vendra-Webhooks/1.0',
                'Webhook-Id' => $delivery->event_id,
                'Webhook-Timestamp' => (string) $timestamp,
                WebhookSignature::HEADER => WebhookSignature::header($delivery->body, $subscription->signingSecrets($now), $timestamp),
            ], $timeout);
        } catch (UnsafeWebhookUrl $unsafe) {
            $result = new TransportResult(null, 0, error: 'Blocked: '.$unsafe->getMessage());
        }

        return $this->record($deliveryId, $result);
    }

    private function claim(string $deliveryId, CarbonImmutable $now, int $timeout): bool
    {
        return DB::table('webhook_deliveries')
            ->where('id', $deliveryId)
            ->whereIn('status', DeliveryStatus::dueValues())
            ->where(static fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now->utc()->format('Y-m-d H:i:s.uP')))
            ->update(['next_attempt_at' => $now->addSeconds($timeout + self::LEASE_MARGIN)->utc()->format('Y-m-d H:i:s.uP')]) === 1;
    }

    private function release(string $deliveryId, CarbonImmutable $now): void
    {
        DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['next_attempt_at' => $now->utc()->format('Y-m-d H:i:s.uP')]);
    }

    private function record(string $deliveryId, TransportResult $result): WebhookDelivery
    {
        $paused = null;

        $delivery = $this->transaction->run(function () use ($deliveryId, $result, &$paused): WebhookDelivery {
            $delivery = WebhookDelivery::query()->whereKey($deliveryId)->firstOrFail();
            $subscription = WebhookSubscription::query()->whereKey($delivery->subscription_id)->lockForUpdate()->first();
            $now = $this->clock->now();

            $delivery->attempts++;
            $delivery->last_attempt_at = $now;
            $delivery->response_code = $result->status;
            $delivery->response_ms = $result->milliseconds;
            $delivery->response_excerpt = $result->excerpt;
            $delivery->error = $result->error ?? ($result->succeeded() ? null : sprintf('The receiver answered %d.', (int) $result->status));

            if ($result->succeeded()) {
                $delivery->status = DeliveryStatus::Succeeded;
                $delivery->delivered_at = $now;
                $delivery->next_attempt_at = null;
                $subscription?->forceFill(['failure_count' => 0])->save();
            } else {
                $this->scheduleRetry($delivery, $now);

                // A test ping says nothing about the subscription's health.
                if ($subscription instanceof WebhookSubscription && ! $delivery->isTest()) {
                    $paused = $this->countFailure($subscription, $now);
                }
            }

            $delivery->save();

            return $delivery;
        });

        if ($paused instanceof WebhookSubscription) {
            $this->alerts->paused($paused);
        }

        return $delivery;
    }

    private function scheduleRetry(WebhookDelivery $delivery, CarbonImmutable $now): void
    {
        /** @var list<int> $schedule */
        $schedule = (array) $this->config->get('webhooks.retry_schedule', []);
        $retry = $schedule[$delivery->attempts - 1] ?? null;

        if ($delivery->isTest() || $retry === null) {
            $delivery->status = DeliveryStatus::Dead;
            $delivery->next_attempt_at = null;

            return;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->next_attempt_at = $now->addSeconds($retry);
    }

    /**
     * @return WebhookSubscription|null the subscription if this failure paused it
     */
    private function countFailure(WebhookSubscription $subscription, CarbonImmutable $now): ?WebhookSubscription
    {
        $subscription->failure_count++;
        $threshold = $this->config->integer('webhooks.pause_after_failures', 10);
        $pausing = $subscription->status === SubscriptionStatus::Active && $subscription->failure_count >= $threshold;

        if ($pausing) {
            $subscription->pause(PauseReason::Failing, $now);
        }

        $subscription->save();

        return $pausing ? $subscription : null;
    }
}
