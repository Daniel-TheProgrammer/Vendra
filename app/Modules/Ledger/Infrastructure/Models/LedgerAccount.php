<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\AccountType;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property Side $normal_balance
 * @property Currency $currency
 * @property bool $is_system
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AccountBalanceRecord|null $balance
 */
final class LedgerAccount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'currency',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'type' => AccountType::class,
            'normal_balance' => Side::class,
            'currency' => Currency::class,
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasOne<AccountBalanceRecord, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(AccountBalanceRecord::class, 'account_id');
    }
}
