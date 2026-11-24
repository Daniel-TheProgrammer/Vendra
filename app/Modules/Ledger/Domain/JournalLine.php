<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * One side of a journal entry: an amount debited or credited to an account.
 * Amounts are always positive; the side carries the direction.
 */
final readonly class JournalLine
{
    public function __construct(
        public string $accountId,
        public Side $side,
        public Money $amount,
        public ?string $memo = null,
    ) {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('A journal line amount must be positive; use the other side to reduce an account.');
        }
    }

    public function reversed(): self
    {
        return new self($this->accountId, $this->side->opposite(), $this->amount, $this->memo);
    }
}
