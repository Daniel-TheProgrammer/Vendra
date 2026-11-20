<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

use App\Shared\Domain\Exception\DomainException;

final class CurrencyMismatch extends DomainException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf('Cannot operate on %s and %s amounts together.', $left->value, $right->value));
    }

    public function type(): string
    {
        return 'currency-mismatch';
    }

    public function title(): string
    {
        return 'Currency mismatch';
    }
}
