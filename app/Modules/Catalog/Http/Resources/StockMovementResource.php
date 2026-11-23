<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\StockMovement;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class StockMovementResource extends ApiResource
{
    public function __construct(StockMovement $movement)
    {
        parent::__construct($movement);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockMovement $movement */
        $movement = $this->resource;

        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'quantity' => $movement->quantity,
            'reason' => $movement->reason,
            'reference' => $movement->reference,
            'note' => $movement->note,
            'on_hand_after' => $movement->on_hand_after,
            'reserved_after' => $movement->reserved_after,
            'actor_id' => $movement->actor_id,
            'created_at' => $movement->created_at->toIso8601String(),
        ];
    }
}
