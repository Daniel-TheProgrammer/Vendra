<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\Actions\ArchiveProduct;
use App\Modules\Catalog\Application\Actions\CreateProduct;
use App\Modules\Catalog\Application\Actions\UpdateProduct;
use App\Modules\Catalog\Application\Commands\CreateProductCommand;
use App\Modules\Catalog\Application\Queries\FindProduct;
use App\Modules\Catalog\Application\Queries\ListProducts;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Http\Concerns\HandlesProductVersions;
use App\Modules\Catalog\Http\Requests\StoreProductRequest;
use App\Modules\Catalog\Http\Requests\UpdateProductRequest;
use App\Modules\Catalog\Http\Resources\ProductResource;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Infrastructure\Http\Resources\SparseFieldset;
use App\Shared\Infrastructure\Http\Rfc3339;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductController
{
    use HandlesProductVersions;

    /**
     * GET /products?status=active&search=shirt&sku=TEE-1&updated_since=2026-11-23T08:00:00Z
     *              &fields=name,status&per_page=25&cursor=...
     */
    public function index(Request $request, ListProducts $list): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(ProductStatus::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'sku' => ['sometimes', 'string', 'max:64'],
            'updated_since' => ['sometimes', ...Rfc3339::rules()],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.ListProducts::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);
        $fields = SparseFieldset::fromRequest($request, ProductResource::FIELDS);

        $page = $list(
            status: $request->enum('status', ProductStatus::class),
            search: isset($filters['search']) ? $request->string('search')->trim()->value() : null,
            sku: isset($filters['sku']) ? $request->string('sku')->value() : null,
            updatedSince: isset($filters['updated_since']) ? Rfc3339::parse($request->string('updated_since')->value()) : null,
            withVariants: $fields->includes('variants'),
            perPage: $request->integer('per_page', 25),
            cursor: isset($filters['cursor']) ? $request->string('cursor')->value() : null,
        );

        return ProductResource::collection($page->through(static fn (Product $product): ProductResource => new ProductResource($product, $fields)))
            ->response($request);
    }

    public function show(Request $request, string $product, FindProduct $find): JsonResponse
    {
        $fields = SparseFieldset::fromRequest($request, ProductResource::FIELDS);

        return $this->productResponse($request, $find($product), fields: $fields);
    }

    public function store(StoreProductRequest $request, TenantContext $context, CreateProduct $create): JsonResponse
    {
        $product = $create(new CreateProductCommand(
            tenantId: $context->id(),
            name: $request->productName(),
            description: $request->description(),
            status: $request->status(),
            variants: $request->variants(),
        ));

        return $this->productResponse($request, $product, 201)
            ->header('Location', route('api.v1.products.show', ['product' => $product->id]));
    }

    public function update(UpdateProductRequest $request, string $product, UpdateProduct $update): JsonResponse
    {
        return $this->productResponse($request, $update($request->toCommand($product, $this->expectedVersion($request))));
    }

    public function archive(Request $request, string $product, ArchiveProduct $archive): JsonResponse
    {
        return $this->productResponse($request, $archive($product, $this->expectedVersion($request)));
    }
}
