<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;

/**
 * A balanced entry. Only JournalEntry::post() makes one, so holding one
 * proves the rules were checked. It has no way to change: a mistake is
 * corrected by posting reverse(), which keeps the original and its undoing
 * both on the record.
 */
final readonly class PostedJournalEntry
{
    /**
     * @param  non-empty-list<JournalLine>  $lines
     */
    public function __construct(
        public Currency $currency,
        public string $description,
        public array $lines,
        /** Total of either side; they are equal. */
        public Money $total,
    ) {}

    /**
     * The entry that cancels this one: every line on the opposite side.
     * Reversing the reversal gives back the original lines.
     */
    public function reverse(string $description): self
    {
        return new self($this->currency, $description, array_map(static fn (JournalLine $line): JournalLine => $line->reversed(), $this->lines), $this->total);
    }
}
