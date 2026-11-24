<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Actions;

use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Domain\EntryAlreadyReversed;
use App\Modules\Ledger\Domain\EntryKind;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * The only way to undo a posting: a new entry with every line on the
 * opposite side, linked to the original. Both stay on the record, so the
 * history shows what was booked, when, and when and why it was undone.
 *
 * An entry is reversed at most once (also a unique index); a reversal can
 * itself be reversed, which books the original lines again.
 */
final readonly class ReverseJournalEntry
{
    public function __construct(
        private TransactionRunner $transaction,
        private PostJournalEntry $post,
    ) {}

    /**
     * @throws EntityNotFound
     * @throws EntryAlreadyReversed
     */
    public function __invoke(string $entryId, string $reason, ?string $actorId = null): JournalEntryRecord
    {
        return $this->transaction->run(function () use ($entryId, $reason, $actorId): JournalEntryRecord {
            // Locks the original, so two reversals of it cannot both pass the check.
            $original = JournalEntryRecord::query()->with('lines')->whereKey($entryId)->lockForUpdate()->first()
                ?? throw EntityNotFound::withId('Journal entry', $entryId);

            $existing = JournalEntryRecord::query()->where('reverses_entry_id', $original->id)->first();

            if ($existing instanceof JournalEntryRecord) {
                throw EntryAlreadyReversed::by($original->id, $existing->id);
            }

            return ($this->post)(
                $original->toPosted()->reverse('Reversal of: '.$original->description),
                new EntryMetadata(
                    idempotencyKey: 'reversal:'.$original->id,
                    kind: EntryKind::Reversal,
                    referenceType: $original->reference_type,
                    referenceId: $original->reference_id,
                    reason: $reason,
                    actorId: $actorId,
                    reversesEntryId: $original->id,
                ),
            );
        });
    }
}
