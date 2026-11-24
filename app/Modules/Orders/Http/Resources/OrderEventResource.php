<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Infrastructure\Models\OrderEventRecord;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class OrderEventResource extends ApiResource
{
    public function __construct(OrderEventRecord $event)
    {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderEventRecord $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'type' => $event->type,
            'from_status' => $event->from_status?->value,
            'to_status' => $event->to_status->value,
            'data' => (object) $event->data,
            'actor_id' => $event->actor_id,
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }
}
