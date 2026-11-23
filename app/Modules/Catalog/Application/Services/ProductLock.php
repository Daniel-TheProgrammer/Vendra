<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\ProductIsArchived;
use App\Modules\Catalog\Domain\ProductVersionMismatch;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Every catalog write locks the product row first. That serialises
 * concurrent writers to one product and makes the version check and the
 * version bump atomic. Call it inside a transaction.
 */
final readonly class ProductLock
{
    public function acquire(string $productId, ?int $expectedVersion = null): Product
    {
        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

        if (! $product instanceof Product) {
            throw EntityNotFound::withId('Product', $productId);
        }

        if ($expectedVersion !== null && $expectedVersion !== $product->version) {
            throw ProductVersionMismatch::expected($expectedVersion, $product->version);
        }

        return $product;
    }

    /**
     * As acquire(), for changes an archived product no longer accepts.
     */
    public function acquireEditable(string $productId, ?int $expectedVersion = null): Product
    {
        $product = $this->acquire($productId, $expectedVersion);

        if ($product->isArchived()) {
            throw ProductIsArchived::create($product->id);
        }

        return $product;
    }
}
