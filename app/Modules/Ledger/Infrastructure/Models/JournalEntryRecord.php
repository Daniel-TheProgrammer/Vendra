<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Models;

use App\Modules\Ledger\Domain\EntryKind;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\PostedJournalEntry;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A posted journal entry. Append-only: the database rejects updates and
 * deletes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Currency $currency
 * @property string $description
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $idempotency_key
 * @property string|null $reverses_entry_id
 * @property EntryKind $kind
 * @property string|null $reason
 * @property string|null $actor_id
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, JournalLineRecord> $lines
 * @property-read JournalEntryRecord|null $reversedBy
 */
final class JournalEntryRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'journal_entries';

    protected $fillable = [
        'currency',
        'description',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'reverses_entry_id',
        'kind',
        'reason',
        'actor_id',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'currency' => Currency::class,
            'kind' => EntryKind::class,
            'posted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<JournalLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLineRecord::class, 'entry_id')->orderBy('position');
    }

    /**
     * @return HasOne<JournalEntryRecord, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * Back to the domain, e.g. to reverse it.
     */
    public function toPosted(): PostedJournalEntry
    {
        $lines = array_values(array_map(static fn (JournalLineRecord $line): JournalLine => $line->toLine(), $this->lines->all()));

        if ($lines === []) {
            throw new LogicException(sprintf('Journal entry %s has no lines; the database never commits one like that.', $this->id));
        }

        $total = 0;

        foreach ($lines as $line) {
            $total += $line->side === Side::Debit ? $line->amount->amount : 0;
        }

        return new PostedJournalEntry($this->currency, $this->description, $lines, Money::of($total, $this->currency));
    }
}
