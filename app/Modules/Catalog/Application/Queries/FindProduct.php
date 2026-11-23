<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindProduct
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $productId): Product
    {
        $product = Product::query()->with('variants.prices')->whereKey($productId)->first();

        return $product instanceof Product ? $product : throw EntityNotFound::withId('Product', $productId);
    }
}
