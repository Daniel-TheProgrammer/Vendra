<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\Price;
use App\Modules\Catalog\Domain\PriceWindow;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * A variant's price in one currency, either its base price (no window) or a
 * scheduled one. There is no exchange-rate conversion: a variant is sellable
 * in exactly the currencies it has a price for at that moment.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variant_id
 * @property Currency $currency
 * @property int $amount
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class VariantPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'variant_id',
        'currency',
        'amount',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'currency' => Currency::class,
            'amount' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_to' => 'immutable_datetime',
        ];
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function toPrice(): Price
    {
        return new Price($this->money(), new PriceWindow($this->valid_from, $this->valid_to), $this->id);
    }
}
