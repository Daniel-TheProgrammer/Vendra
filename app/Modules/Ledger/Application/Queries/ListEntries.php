<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;

/**
 * Journal entries, newest first, keyset-paginated. The posted-at range is
 * half-open: [postedFrom, postedTo).
 */
final readonly class ListEntries
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, JournalEntryRecord>
     */
    public function __invoke(
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?DateTimeInterface $postedFrom = null,
        ?DateTimeInterface $postedTo = null,
        int $perPage = 25,
        ?string $cursor = null,
    ): CursorPaginator {
        return self::filtered($referenceType, $referenceId, $postedFrom, $postedTo)
            ->with('lines.account', 'reversedBy')
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }

    /**
     * @return Builder<JournalEntryRecord>
     */
    public static function filtered(?string $referenceType, ?string $referenceId, ?DateTimeInterface $postedFrom, ?DateTimeInterface $postedTo): Builder
    {
        return JournalEntryRecord::query()
            ->when($referenceType, static fn (Builder $query, string $type) => $query->where('reference_type', $type))
            ->when($referenceId, static fn (Builder $query, string $id) => $query->where('reference_id', $id))
            ->when($postedFrom, static fn (Builder $query, DateTimeInterface $from) => $query->where('posted_at', '>=', $from->format('Y-m-d H:i:s.uP')))
            ->when($postedTo, static fn (Builder $query, DateTimeInterface $to) => $query->where('posted_at', '<', $to->format('Y-m-d H:i:s.uP')));
    }
}
