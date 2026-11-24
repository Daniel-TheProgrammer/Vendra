<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Pricing;

use App\Modules\Orders\Domain\Pricing\TaxStrategy;
use App\Modules\Orders\Domain\ShippingAddress;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;

/**
 * Totals for a set of priced lines. All arithmetic is in integer minor
 * units; tax is computed once on the subtotal, so the order total never
 * drifts from the sum of its lines by per-line rounding.
 */
final readonly class PricingService
{
    public function __construct(private TaxStrategy $tax) {}

    /**
     * @param  non-empty-list<PricedLine>  $lines
     */
    public function totals(Currency $currency, array $lines, ?ShippingAddress $destination): OrderTotals
    {
        $subtotal = Money::zero($currency);

        foreach ($lines as $line) {
            $subtotal = $subtotal->add($line->lineTotal);
        }

        return new OrderTotals($subtotal, $this->tax->taxFor($subtotal, $destination));
    }
}
