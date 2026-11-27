<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Resources;

use App\Shared\Infrastructure\Http\Resources\ApiResource;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use Illuminate\Http\Request;
use stdClass;

/**
 * An event as webhook subscribers receive it, plus where it is in the
 * outbox.
 */
final class EventResource extends ApiResource
{
    public function __construct(OutboxMessage $message)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OutboxMessage $message */
        $message = $this->resource;

        return [
            'id' => $message->id,
            'type' => $message->event_type,
            'created_at' => $message->occurred_at->toIso8601ZuluString('microsecond'),
            'data' => $message->payload === [] ? new stdClass : $message->payload,
            'published_at' => $message->published_at?->toIso8601String(),
            'failed' => $message->dead_at !== null,
        ];
    }
}
