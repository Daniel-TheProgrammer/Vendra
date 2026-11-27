<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Clock\Clock;
use App\Shared\Infrastructure\Outbox\EventPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Creates one delivery per subscription that wants the event, in the
 * current tenant. Runs inside the outbox relay's savepoint, so the
 * deliveries commit with the event's published mark; the unique
 * (subscription, event) pair makes a republished event a no-op.
 *
 * Paused subscriptions get their deliveries too: they wait, and go out
 * when the subscription is resumed.
 */
final readonly class FanOutEvent
{
    public function __construct(
        private Clock $clock,
    ) {}

    /**
     * @return list<string> ids of the deliveries created now
     */
    public function __invoke(EventPublished $event): array
    {
        $subscriptions = WebhookSubscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Paused])
            ->get()
            ->filter(static fn (WebhookSubscription $subscription): bool => $subscription->wants($event->type));

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $body = self::body($event->eventId, $event->type, $event->occurredAt->toIso8601ZuluString('microsecond'), $event->tenantId, $event->payload);
        $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.uP');
        $created = [];

        foreach ($subscriptions as $subscription) {
            $id = (string) Str::uuid7();
            $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $event->tenantId,
                'subscription_id' => $subscription->id,
                'event_id' => $event->eventId,
                'event_type' => $event->type,
                'body' => $body,
                'status' => DeliveryStatus::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1 && $subscription->status === SubscriptionStatus::Active) {
                $created[] = $id;
            }
        }

        return $created;
    }

    /**
     * The JSON every delivery carries (docs/webhooks.md).
     *
     * @param  array<string, mixed>  $data
     */
    public static function body(string $eventId, string $type, string $createdAt, string $tenantId, array $data): string
    {
        return json_encode([
            'id' => $eventId,
            'type' => $type,
            'created_at' => $createdAt,
            'tenant_id' => $tenantId,
            'data' => $data === [] ? new stdClass : $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
