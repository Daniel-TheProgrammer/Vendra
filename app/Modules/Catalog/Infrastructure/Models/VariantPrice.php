<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * One price per variant and currency. There is no exchange-rate conversion:
 * a variant is sellable in exactly the currencies it has a price for.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variant_id
 * @property Currency $currency
 * @property int $amount
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
        ];
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
