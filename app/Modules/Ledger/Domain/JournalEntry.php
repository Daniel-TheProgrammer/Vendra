<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * A journal entry being written. Immutable: every debit() or credit()
 * returns a new draft, so a half-built entry can never leak in a changed
 * state. post() checks the rules of double entry and returns a
 * PostedJournalEntry, which can no longer change at all; correcting it means
 * posting its reverse().
 *
 *   JournalEntry::draft(Currency::USD, 'Order 1001 placed')
 *       ->debit($receivable, Money::of(1200, Currency::USD))
 *       ->credit($revenue, Money::of(1000, Currency::USD))
 *       ->credit($taxPayable, Money::of(200, Currency::USD))
 *       ->post();
 */
final readonly class JournalEntry
{
    /**
     * @param  list<JournalLine>  $lines
     */
    private function __construct(
        public Currency $currency,
        public string $description,
        public array $lines,
    ) {}

    public static function draft(Currency $currency, string $description): self
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A journal entry needs a description.');
        }

        return new self($currency, $description, []);
    }

    public function debit(string $accountId, Money $amount, ?string $memo = null): self
    {
        return $this->with(new JournalLine($accountId, Side::Debit, $amount, $memo));
    }

    public function credit(string $accountId, Money $amount, ?string $memo = null): self
    {
        return $this->with(new JournalLine($accountId, Side::Credit, $amount, $memo));
    }

    /**
     * Splits $total across accounts by ratio without losing or inventing a
     * minor unit: the parts always add back up to $total exactly.
     *
     * @param  array<string, int>  $ratios  account id => ratio
     */
    public function split(Side $side, Money $total, array $ratios): self
    {
        $entry = $this;
        $parts = $total->allocate(...array_values($ratios));

        foreach (array_keys($ratios) as $index => $accountId) {
            if ($parts[$index]->isPositive()) {
                $entry = $entry->with(new JournalLine($accountId, $side, $parts[$index]));
            }
        }

        return $entry;
    }

    /**
     * @throws InvalidJournalEntry
     */
    public function post(): PostedJournalEntry
    {
        $hasDebit = false;
        $hasCredit = false;
        $debits = 0;
        $credits = 0;

        foreach ($this->lines as $line) {
            if ($line->amount->currency !== $this->currency) {
                throw InvalidJournalEntry::mixedCurrencies($this->currency->value, $line->amount->currency->value);
            }

            if ($line->side === Side::Debit) {
                $hasDebit = true;
                $debits += $line->amount->amount;
            } else {
                $hasCredit = true;
                $credits += $line->amount->amount;
            }
        }

        if (! $hasDebit || ! $hasCredit) {
            throw InvalidJournalEntry::tooFewLines(count($this->lines));
        }

        if ($debits !== $credits) {
            throw InvalidJournalEntry::unbalanced($debits, $credits);
        }

        return new PostedJournalEntry($this->currency, $this->description, $this->lines, Money::of($debits, $this->currency));
    }

    private function with(JournalLine $line): self
    {
        return new self($this->currency, $this->description, [...$this->lines, $line]);
    }
}
