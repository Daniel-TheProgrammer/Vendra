<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Concerns;

use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Infrastructure\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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
        $header = $request->header('If-Match');

        if (! is_string($header) || trim($header) === '' || trim($header) === '*') {
            return null;
        }

        if (preg_match('/^(?:W\/)?"(\d{1,9})"$/', trim($header), $match) !== 1) {
            throw new BadRequestHttpException('If-Match must be a single ETag previously returned for this product, e.g. "3".');
        }

        return (int) $match[1];
    }

    protected function productResponse(Request $request, Product $product, int $status = 200): JsonResponse
    {
        $product->loadMissing('variants.prices', 'variants.inventory');

        return (new ProductResource($product))
            ->toResponse($request)
            ->setStatusCode($status)
            ->header('ETag', sprintf('"%d"', $product->version));
    }
}
