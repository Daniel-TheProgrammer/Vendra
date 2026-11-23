<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Domain\Price;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Currency;
use DateTimeInterface;

/**
 * The price a variant sells for in a currency at an instant (now by
 * default), or null when it is not for sale in that currency then. This is
 * what order placement prices lines with.
 */
final readonly class FindVariantPrice
{
    public function __construct(private Clock $clock) {}

    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $variantId, Currency $currency, ?DateTimeInterface $at = null): ?Price
    {
        $variant = ProductVariant::query()->with('prices')->whereKey($variantId)->first()
            ?? throw EntityNotFound::withId('Variant', $variantId);

        return $variant->priceList()->effectiveAt($currency, $at ?? $this->clock->now());
    }
}
