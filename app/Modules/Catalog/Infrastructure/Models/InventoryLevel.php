<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\StockLevel;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * One row per variant, created on first use. Written only through
 * StockLedger, under a row lock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variant_id
 * @property int $on_hand
 * @property int $reserved
 * @property int $version
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class InventoryLevel extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'on_hand' => 'integer',
            'reserved' => 'integer',
            'version' => 'integer',
        ];
    }

    public function toStockLevel(): StockLevel
    {
        return new StockLevel($this->variant_id, $this->on_hand, $this->reserved);
    }
}
