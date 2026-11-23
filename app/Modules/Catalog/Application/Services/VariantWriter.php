<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Domain\SkuAlreadyExists;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes variants and their prices. Call inside a transaction, with the
 * product locked.
 */
final readonly class VariantWriter
{
    /**
     * Fails before writing anything if one of the SKUs is taken, so the client
     * gets the first conflicting SKU rather than a half-created product.
     *
     * @param  list<string>  $skus
     */
    public function ensureSkusAreFree(array $skus): void
    {
        $taken = ProductVariant::query()->whereIn('sku', $skus)->orderBy('sku')->value('sku');

        if (is_string($taken)) {
            throw SkuAlreadyExists::for($taken);
        }
    }

    public function add(Product $product, VariantData $data): ProductVariant
    {
        try {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $data->sku,
                'name' => $data->name,
                'options' => $data->options,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request claimed the SKU after the check above.
            throw SkuAlreadyExists::for($data->sku);
        }

        foreach ($data->prices as $price) {
            VariantPrice::query()->create([
                'variant_id' => $variant->id,
                'currency' => $price->currency,
                'amount' => $price->amount,
            ]);
        }

        return $variant;
    }
}
