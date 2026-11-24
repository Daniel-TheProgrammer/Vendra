<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $entry_id
 * @property int $position
 * @property string $account_id
 * @property Currency $currency
 * @property int $debit
 * @property int $credit
 * @property string|null $memo
 * @property-read LedgerAccount $account
 */
final class JournalLineRecord extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'journal_lines';

    protected $fillable = [
        'entry_id',
        'position',
        'account_id',
        'currency',
        'debit',
        'credit',
        'memo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'currency' => Currency::class,
            'debit' => 'integer',
            'credit' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function toLine(): JournalLine
    {
        return $this->debit > 0
            ? new JournalLine($this->account_id, Side::Debit, Money::of($this->debit, $this->currency), $this->memo)
            : new JournalLine($this->account_id, Side::Credit, Money::of($this->credit, $this->currency), $this->memo);
    }
}
