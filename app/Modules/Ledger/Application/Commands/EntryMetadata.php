<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Commands;

use App\Modules\Ledger\Domain\EntryKind;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Everything about a posting except its lines.
 */
final readonly class EntryMetadata
{
    public function __construct(
        /** Unique per tenant; posting the same key twice returns the first entry. */
        public string $idempotencyKey,
        public EntryKind $kind = EntryKind::System,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $reason = null,
        public ?string $actorId = null,
        /** When the business event happened; defaults to now. */
        public ?DateTimeInterface $postedAt = null,
        /** Set exactly when kind is Reversal. */
        public ?string $reversesEntryId = null,
    ) {
        if (($kind === EntryKind::Reversal) !== ($reversesEntryId !== null)) {
            throw new InvalidArgumentException('A reversal names the entry it reverses, and only a reversal does.');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 150) {
            throw new InvalidArgumentException('An entry idempotency key must be 1 to 150 characters.');
        }

        if ($kind === EntryKind::Manual && trim((string) $reason) === '') {
            throw new InvalidArgumentException('A manual entry needs a reason.');
        }
    }
}
