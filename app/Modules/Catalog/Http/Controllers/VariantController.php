<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\AddVariant;
use App\Modules\Catalog\Application\Actions\RemoveVariantPrice;
use App\Modules\Catalog\Application\Actions\SetVariantPrice;
use App\Modules\Catalog\Application\Queries\FindProduct;
use App\Modules\Catalog\Http\Concerns\HandlesProductVersions;
use App\Modules\Catalog\Http\Requests\SetPriceRequest;
use App\Modules\Catalog\Http\Requests\StoreVariantRequest;
use App\Shared\Domain\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Variant and price writes answer with the whole product, so the client gets
 * the new version (ETag) to chain its next conditional write.
 */
final class VariantController
{
    use HandlesProductVersions;

    public function store(StoreVariantRequest $request, string $product, AddVariant $add, FindProduct $find): JsonResponse
    {
        $add($product, $request->variant(), $this->expectedVersion($request));

        return $this->productResponse($request, $find($product), 201);
    }

    public function setPrice(SetPriceRequest $request, string $variant, SetVariantPrice $set): JsonResponse
    {
        return $this->productResponse($request, $set($variant, $request->price(), $this->expectedVersion($request)));
    }

    public function removePrice(Request $request, string $variant, string $currency, RemoveVariantPrice $remove): JsonResponse
    {
        return $this->productResponse($request, $remove($variant, Currency::from($currency), $this->expectedVersion($request)));
    }
}
