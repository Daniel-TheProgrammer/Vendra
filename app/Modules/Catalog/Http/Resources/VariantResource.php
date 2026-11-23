<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Price;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Application\Clock\Clock;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use DateTimeInterface;
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
        $now = app(Clock::class)->now();
        $prices = $variant->priceList();

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'name' => $variant->name,
            // Always a JSON object, even when empty.
            'options' => (object) $variant->options,
            // What the variant sells for right now, one entry per currency.
            'prices' => array_map(self::amount(...), $prices->allEffectiveAt($now)),
            // Sales and announced changes that are running or still to come.
            'scheduled_prices' => array_map(static fn (Price $price): array => [
                'id' => $price->id,
                ...self::amount($price),
                'valid_from' => $price->window->from?->format(DateTimeInterface::RFC3339),
                'valid_to' => $price->window->to?->format(DateTimeInterface::RFC3339),
            ], $prices->upcomingScheduledAt($now)),
            'inventory' => [
                'on_hand' => $variant->inventory->on_hand ?? 0,
                'reserved' => $variant->inventory->reserved ?? 0,
                'available' => ($variant->inventory->on_hand ?? 0) - ($variant->inventory->reserved ?? 0),
            ],
        ];
    }

    /**
     * @return array{currency: string, amount: string, amount_minor: int}
     */
    private static function amount(Price $price): array
    {
        return [
            'currency' => $price->amount->currency->value,
            'amount' => $price->amount->toDecimal(),
            'amount_minor' => $price->amount->amount,
        ];
    }
}
