<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Pricing;

use App\Modules\Orders\Domain\ShippingAddress;
use App\Shared\Domain\Money\Money;

/**
 * How much tax an order owes. One implementation is bound per deployment
 * (OrdersServiceProvider); destination-based or third-party tax engines slot
 * in here without touching order placement.
 */
interface TaxStrategy
{
    /**
     * @return Money in the subtotal's currency, never negative
     */
    public function taxFor(Money $subtotal, ?ShippingAddress $destination): Money;
}
