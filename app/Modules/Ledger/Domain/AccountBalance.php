<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

use App\Shared\Domain\Money\Money;

/**
 * What an account holds: the totals of each side, and their difference read
 * in the account's normal direction. A receivable with 100 debited and 30
 * credited holds 70; a revenue account with 100 credited holds 100. A
 * negative balance is legitimate (an overdrawn asset) and stays visible.
 */
final readonly class AccountBalance
{
    public function __construct(
        public Money $debits,
        public Money $credits,
        public Side $normalBalance,
    ) {}

    public function balance(): Money
    {
        return $this->normalBalance === Side::Debit
            ? $this->debits->subtract($this->credits)
            : $this->credits->subtract($this->debits);
    }
}
