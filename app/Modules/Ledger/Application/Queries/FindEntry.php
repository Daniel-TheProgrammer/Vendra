<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindEntry
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $entryId): JournalEntryRecord
    {
        return JournalEntryRecord::query()->with('lines.account', 'reversedBy')->whereKey($entryId)->first()
            ?? throw EntityNotFound::withId('Journal entry', $entryId);
    }
}
