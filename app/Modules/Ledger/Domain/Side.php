<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
