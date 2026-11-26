<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Resources;

use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Never carries the signing secret: that has its own endpoint
 * (GET …/secret), so no response that may be stored (idempotency replays,
 * logs, caches) ever holds it.
 */
final class SubscriptionResource extends ApiResource
{
    public function __construct(WebhookSubscription $subscription)
    {
        parent::__construct($subscription);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookSubscription $subscription */
        $subscription = $this->resource;

        return [
            'id' => $subscription->id,
            'url' => $subscription->url,
            'description' => $subscription->description,
            'event_types' => $subscription->event_types,
            'status' => $subscription->status->value,
            'paused_reason' => $subscription->paused_reason?->value,
            'paused_at' => $subscription->paused_at?->toIso8601String(),
            'consecutive_failures' => $subscription->failure_count,
            'previous_secret_expires_at' => $subscription->previous_secret_expires_at?->toIso8601String(),
            'version' => $subscription->version,
            'created_at' => $subscription->created_at?->toIso8601String(),
            'updated_at' => $subscription->updated_at?->toIso8601String(),
        ];
    }
}
