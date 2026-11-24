<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Actions;

use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Application\Commands\ManualEntryCommand;
use App\Modules\Ledger\Domain\EntryKind;
use App\Modules\Ledger\Domain\InvalidJournalEntry;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Domain\Side;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use Illuminate\Support\Str;

/**
 * Posts a hand-written entry through the same rules as every other: it must
 * balance, be in one currency and touch accounts of that currency. Retries
 * are made safe by the request's Idempotency-Key (ADR 0007); each accepted
 * request is a distinct entry.
 */
final readonly class PostManualEntry
{
    public function __construct(private PostJournalEntry $post) {}

    /**
     * @throws InvalidJournalEntry
     */
    public function __invoke(ManualEntryCommand $command): JournalEntryRecord
    {
        $entry = JournalEntry::draft($command->currency, $command->description);

        foreach ($command->lines as $line) {
            $entry = $line['side'] === Side::Debit
                ? $entry->debit($line['account_id'], $line['amount'], $line['memo'])
                : $entry->credit($line['account_id'], $line['amount'], $line['memo']);
        }

        return ($this->post)($entry->post(), new EntryMetadata(
            idempotencyKey: 'manual:'.Str::uuid7(),
            kind: EntryKind::Manual,
            referenceType: 'manual',
            reason: $command->reason,
            actorId: $command->actorId,
        ));
    }
}
