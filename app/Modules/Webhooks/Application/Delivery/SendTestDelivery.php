<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Webhooks\Application\Queries\FindSubscription;
use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Support\Str;

/**
 * POST /webhooks/subscriptions/{id}/test: a "webhook.test" event, signed
 * like any other and sent at once, so a tenant can check its receiver. One
 * attempt, no retries, and it never counts against the subscription.
 */
final readonly class SendTestDelivery
{
    public function __construct(
        private FindSubscription $subscriptions,
        private AttemptDelivery $attempt,
        private Clock $clock,
    ) {}

    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $subscriptionId): WebhookDelivery
    {
        $subscription = ($this->subscriptions)($subscriptionId);
        $eventId = (string) Str::uuid7();
        $now = $this->clock->now();

        $delivery = WebhookDelivery::query()->create([
            'subscription_id' => $subscription->id,
            'event_id' => $eventId,
            'event_type' => WebhookDelivery::TEST_EVENT,
            'body' => FanOutEvent::body($eventId, WebhookDelivery::TEST_EVENT, $now->toIso8601ZuluString('microsecond'), $subscription->tenant_id, [
                'subscription_id' => $subscription->id,
                'message' => 'A test delivery from Vendra. Verify its signature, then answer 2xx.',
            ]),
            'status' => DeliveryStatus::Pending,
            'next_attempt_at' => $now,
        ]);

        return ($this->attempt)($delivery->id) ?? $delivery->refresh();
    }
}
