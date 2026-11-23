<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Prices are given both as a decimal string for display and as an integer
 * number of minor units for arithmetic; clients should never parse the former
 * into a float.
 */
final class VariantResource extends ApiResource
{
    public function __construct(ProductVariant $variant)
    {
        parent::__construct($variant);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->resource;

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name,
            // Always a JSON object, even when empty.
            'options' => (object) $variant->options,
            'prices' => $variant->prices
                ->map(static fn (VariantPrice $price): array => [
                    'currency' => $price->currency->value,
                    'amount' => $price->money()->toDecimal(),
                    'amount_minor' => $price->amount,
                ])
                ->values()
                ->all(),
            'inventory' => [
                'on_hand' => $variant->inventory->on_hand ?? 0,
                'reserved' => $variant->inventory->reserved ?? 0,
                'available' => ($variant->inventory->on_hand ?? 0) - ($variant->inventory->reserved ?? 0),
            ],
        ];
    }
}
