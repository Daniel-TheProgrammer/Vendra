<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

/**
 * ISO 4217 currencies the platform can price in.
 *
 * The minor unit is the number of decimal places the currency is divided into;
 * every Money amount is stored as an integer count of that unit.
 */
enum Currency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case CHF = 'CHF';
    case NGN = 'NGN';
    case GHS = 'GHS';
    case KES = 'KES';
    case ZAR = 'ZAR';
    case INR = 'INR';
    case JPY = 'JPY';
    case KWD = 'KWD';
    case BHD = 'BHD';

    public function minorUnit(): int
    {
        return match ($this) {
            self::JPY => 0,
            self::KWD, self::BHD => 3,
            default => 2,
        };
    }

    /**
     * Number of minor units in one major unit (100 for USD, 1 for JPY, 1000 for KWD).
     */
    public function subunitFactor(): int
    {
        return 10 ** $this->minorUnit();
    }
}
