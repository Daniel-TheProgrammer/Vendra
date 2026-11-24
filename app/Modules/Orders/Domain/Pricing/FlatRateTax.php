<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Pricing;

use App\Modules\Orders\Domain\ShippingAddress;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

/**
 * One rate for every order, in basis points (2000 = 20%), rounded half away
 * from zero to the currency's minor unit. A rate of 0 means prices are
 * entered tax-inclusive or tax is handled elsewhere.
 */
final readonly class FlatRateTax implements TaxStrategy
{
    public function __construct(private int $basisPoints)
    {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new InvalidArgumentException('A tax rate must be between 0 and 10000 basis points.');
        }
    }

    public function taxFor(Money $subtotal, ?ShippingAddress $destination): Money
    {
        return $subtotal->ratio($this->basisPoints, 10_000);
    }
}
