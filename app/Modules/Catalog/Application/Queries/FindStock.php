<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Domain\StockLevel;
use App\Modules\Catalog\Infrastructure\Models\InventoryLevel;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Current stock of a variant. A variant whose stock never moved has no row
 * yet and reads as zero.
 */
final readonly class FindStock
{
    public function __invoke(string $variantId): StockLevel
    {
        if (! ProductVariant::query()->whereKey($variantId)->exists()) {
            throw EntityNotFound::withId('Variant', $variantId);
        }

        $level = InventoryLevel::query()->where('variant_id', $variantId)->first();

        return $level instanceof InventoryLevel ? $level->toStockLevel() : new StockLevel($variantId, 0, 0);
    }
}
