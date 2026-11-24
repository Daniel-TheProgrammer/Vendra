<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;

/**
 * Looks variants up for an order. Only variants of active products are
 * sellable; drafts and archived products are reported but flagged.
 */
final readonly class FindSellableVariants
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, SellableVariant> keyed by variant id; unknown ids are absent
     */
    public function __invoke(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $found = [];

        foreach (ProductVariant::query()->with('product')->whereIn('id', array_values(array_unique($variantIds)))->get() as $variant) {
            $found[$variant->id] = new SellableVariant(
                variantId: $variant->id,
                productId: $variant->product_id,
                sku: $variant->sku,
                name: $variant->name !== null ? $variant->product->name.' / '.$variant->name : $variant->product->name,
                isSellable: $variant->product->status === ProductStatus::Active,
            );
        }

        return $found;
    }
}
