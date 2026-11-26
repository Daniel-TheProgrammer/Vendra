<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Actions;

use App\Modules\Webhooks\Application\Security\DestinationGuard;
use App\Modules\Webhooks\Domain\PauseReason;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Domain\UnsafeWebhookUrl;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Exception\VersionMismatch;

/**
 * Changes a subscription's URL, events, description or status. A new URL
 * passes the SSRF guard again. Resuming a paused subscription clears its
 * failure count; deliveries that died meanwhile are redelivered on request.
 */
final readonly class UpdateSubscription
{
    public function __construct(
        private TransactionRunner $transaction,
        private DestinationGuard $guard,
        private Clock $clock,
    ) {}

    /**
     * @throws EntityNotFound
     * @throws VersionMismatch
     * @throws UnsafeWebhookUrl
     */
    public function __invoke(string $id, SubscriptionData $data, ?SubscriptionStatus $status = null, ?int $expectedVersion = null): WebhookSubscription
    {
        if ($data->url !== null) {
            $this->guard->check($data->url);
        }

        return $this->transaction->run(function () use ($id, $data, $status, $expectedVersion): WebhookSubscription {
            $subscription = WebhookSubscription::query()->whereKey($id)->lockForUpdate()->first() ?? throw EntityNotFound::withId('Webhook subscription', $id);

            if ($expectedVersion !== null && $expectedVersion !== $subscription->version) {
                throw VersionMismatch::of('webhook subscription', $expectedVersion, $subscription->version);
            }

            if ($data->url !== null) {
                $subscription->url = $data->url;
            }

            if ($data->eventTypes !== null) {
                $subscription->event_types = array_values(array_unique($data->eventTypes));
            }

            if ($data->updatesDescription) {
                $subscription->description = $data->description;
            }

            if ($subscription->isDirty()) {
                $subscription->version++;
            }

            match (true) {
                $status === SubscriptionStatus::Paused && $subscription->status === SubscriptionStatus::Active => $subscription->pause(PauseReason::Manual, $this->clock->now()),
                $status === SubscriptionStatus::Active && $subscription->status === SubscriptionStatus::Paused => $subscription->resume(),
                default => null,
            };

            $subscription->save();

            return $subscription;
        });
    }
}
