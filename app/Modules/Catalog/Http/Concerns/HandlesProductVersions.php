<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Concerns;

use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Infrastructure\Http\IfMatch;
use App\Shared\Infrastructure\Http\Resources\SparseFieldset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency over HTTP. Every product response carries
 * ETag: "<version>". A client that sends it back in If-Match gets its write
 * applied only if nobody changed the product in between, and a
 * 412 precondition-failed otherwise. Without If-Match the write is
 * unconditional (last write wins).
 */
trait HandlesProductVersions
{
    protected function expectedVersion(Request $request): ?int
    {
        return IfMatch::expectedVersion($request);
    }

    protected function productResponse(Request $request, Product $product, int $status = 200, ?SparseFieldset $fields = null): JsonResponse
    {
        $product->loadMissing('variants.prices', 'variants.inventory');

        return (new ProductResource($product, $fields))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $product->version));
    }
}
