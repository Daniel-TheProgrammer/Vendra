<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * The side that increases the account: assets and expenses grow with
     * debits; liabilities, equity and revenue with credits.
     */
    public function normalBalance(): Side
    {
        return match ($this) {
            self::Asset, self::Expense => Side::Debit,
            self::Liability, self::Equity, self::Revenue => Side::Credit,
        };
    }
}
