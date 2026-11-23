<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Database\Factories;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Domain\Sku;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Domain\Money\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Products are tenant-owned and the tables are under row-level security:
 * create them while the tenant context is set, e.g. inside
 * TenantContext::runAs(). tenant_id is then filled from the context.
 *
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(rtrim(fake()->sentence(3), '.')),
            'description' => fake()->sentence(),
            'status' => ProductStatus::Active,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => ProductStatus::Draft]);
    }

    /**
     * Adds one variant with a price in the given currency.
     */
    public function withVariant(?string $sku = null, int $amount = 1999, Currency $currency = Currency::USD): static
    {
        return $this->afterCreating(static function (Product $product) use ($sku, $amount, $currency): void {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => Sku::normalise($sku ?? Str::random(10)),
                'name' => null,
                'options' => [],
            ]);

            VariantPrice::query()->create([
                'variant_id' => $variant->id,
                'currency' => $currency,
                'amount' => $amount,
            ]);
        });
    }
}
