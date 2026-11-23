<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use App\Shared\Infrastructure\Http\Resources\SparseFieldset;
use Illuminate\Http\Request;

final class ProductResource extends ApiResource
{
    /**
     * Top-level attributes, in output order. These are the names ?fields= accepts.
     */
    public const FIELDS = ['id', 'name', 'description', 'status', 'version', 'variants', 'archived_at', 'created_at', 'updated_at'];

    private readonly SparseFieldset $fields;

    public function __construct(Product $product, ?SparseFieldset $fields = null)
    {
        parent::__construct($product);

        $this->fields = $fields ?? SparseFieldset::all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return $this->fields->apply([
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'status' => $product->status->value,
            'version' => $product->version,
            // Not built at all when the client left it out; the query skips loading it too.
            'variants' => $this->fields->includes('variants')
                ? $product->variants
                    ->map(static fn (ProductVariant $variant): array => (new VariantResource($variant))->toArray($request))
                    ->values()
                    ->all()
                : null,
            'archived_at' => $product->archived_at?->toIso8601String(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ]);
    }
}
