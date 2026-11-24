<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * Materialised running totals of one account. Written only by
 * PostJournalEntry, under a row lock, in the posting's transaction.
 *
 * @property string $account_id
 * @property string $tenant_id
 * @property int $debit_total
 * @property int $credit_total
 * @property int $version
 * @property CarbonImmutable $updated_at
 */
final class AccountBalanceRecord extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    protected $table = 'account_balances';

    protected $primaryKey = 'account_id';

    protected $fillable = [
        'account_id',
        'debit_total',
        'credit_total',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_total' => 'integer',
            'credit_total' => 'integer',
            'version' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
