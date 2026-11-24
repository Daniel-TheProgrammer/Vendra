<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * An entry that would break double entry. Nothing is posted.
 */
final class InvalidJournalEntry extends DomainException
{
    private string $problem = '';

    /** @var array<string, mixed> */
    private array $details = [];

    public static function unbalanced(int $debits, int $credits): self
    {
        return self::make('unbalanced', sprintf('Debits (%d) and credits (%d) must be equal.', $debits, $credits), ['debits' => $debits, 'credits' => $credits]);
    }

    public static function mixedCurrencies(string $entryCurrency, string $lineCurrency): self
    {
        return self::make('mixed-currencies', sprintf('Every line of a %s entry must be in %s, not %s.', $entryCurrency, $entryCurrency, $lineCurrency), [
            'currency' => $entryCurrency,
            'line_currency' => $lineCurrency,
        ]);
    }

    public static function tooFewLines(int $lines): self
    {
        return self::make('too-few-lines', sprintf('An entry needs at least one debit and one credit; it has %d line(s).', $lines), ['lines' => $lines]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function make(string $problem, string $message, array $details): self
    {
        $exception = new self($message);
        $exception->problem = $problem;
        $exception->details = $details;

        return $exception;
    }

    public function problem(): string
    {
        return $this->problem;
    }

    public function type(): string
    {
        return 'journal-entry-invalid';
    }

    public function title(): string
    {
        return 'Journal entry does not balance';
    }

    public function extensions(): array
    {
        return ['problem' => $this->problem, ...$this->details];
    }
}
