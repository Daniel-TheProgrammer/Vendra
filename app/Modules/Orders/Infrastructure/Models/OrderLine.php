<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Models;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property int $position
 * @property string $variant_id
 * @property string $sku
 * @property string $name
 * @property int $unit_price
 * @property int $quantity
 * @property int $line_total
 */
final class OrderLine extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'position',
        'variant_id',
        'sku',
        'name',
        'unit_price',
        'quantity',
        'line_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
        ];
    }

    public function unitPrice(Currency $currency): Money
    {
        return Money::of($this->unit_price, $currency);
    }

    public function lineTotal(Currency $currency): Money
    {
        return Money::of($this->line_total, $currency);
    }
}
