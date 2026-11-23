<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $sku
 * @property string|null $name
 * @property array<string, string> $options
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Product $product
 * @property-read Collection<int, VariantPrice> $prices
 * @property-read InventoryLevel|null $inventory
 */
final class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'options' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Absent until the variant's stock first changes; treat that as zero.
     *
     * @return HasOne<InventoryLevel, $this>
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(InventoryLevel::class, 'variant_id');
    }

    /**
     * @return HasMany<VariantPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class, 'variant_id')->orderBy('currency');
    }
}
