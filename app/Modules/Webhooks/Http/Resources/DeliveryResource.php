<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Resources;

use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class DeliveryResource extends ApiResource
{
    public function __construct(
        WebhookDelivery $delivery,
        /** The body sent, only when one delivery is shown. */
        private readonly bool $withBody = false,
    ) {
        parent::__construct($delivery);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => $delivery->id,
            'subscription_id' => $delivery->subscription_id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status->value,
            'attempts' => $delivery->attempts,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
            'response' => $delivery->last_attempt_at !== null ? [
                'status' => $delivery->response_code,
                'ms' => $delivery->response_ms,
                'excerpt' => $delivery->response_excerpt,
            ] : null,
            'error' => $delivery->error,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            ...($this->withBody ? ['body' => json_decode($delivery->body, true)] : []),
        ];
    }
}
