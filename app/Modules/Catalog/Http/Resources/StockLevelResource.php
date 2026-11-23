<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\StockLevel;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class StockLevelResource extends ApiResource
{
    public function __construct(StockLevel $level)
    {
        parent::__construct($level);
    }

    /**
     * @return array{variant_id: string, on_hand: int, reserved: int, available: int}
     */
    public function toArray(Request $request): array
    {
        /** @var StockLevel $level */
        $level = $this->resource;

        return [
            'variant_id' => $level->variantId,
            'on_hand' => $level->onHand,
            'reserved' => $level->reserved,
            'available' => $level->available(),
        ];
    }
}
