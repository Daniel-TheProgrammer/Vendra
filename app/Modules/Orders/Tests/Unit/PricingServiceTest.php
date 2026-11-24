<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tests\Unit;

use App\Modules\Orders\Application\Pricing\PricedLine;
use App\Modules\Orders\Application\Pricing\PricingService;
use App\Modules\Orders\Domain\Pricing\FlatRateTax;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    private function line(int $unitMinor, int $quantity, Currency $currency = Currency::USD): PricedLine
    {
        return new PricedLine('variant', 'SKU', 'Name', Money::of($unitMinor, $currency), $quantity);
    }

    #[Test]
    public function totals_add_up_in_minor_units(): void
    {
        $totals = (new PricingService(new FlatRateTax(0)))->totals(Currency::USD, [$this->line(1250, 3), $this->line(999, 1)], null);

        self::assertSame(4749, $totals->subtotal->amount);
        self::assertSame(0, $totals->tax->amount);
        self::assertSame(4749, $totals->total->amount);
    }

    #[Test]
    public function tax_is_charged_once_on_the_subtotal_not_per_line(): void
    {
        // 8.25% of each 0.05 line is 0.4125 cents, which rounds to 0: taxed
        // per line, three lines would owe nothing. On the 0.15 subtotal it is
        // 1.2375 cents, which rounds to 1.
        $totals = (new PricingService(new FlatRateTax(825)))->totals(Currency::USD, [$this->line(5, 1), $this->line(5, 1), $this->line(5, 1)], null);

        self::assertSame(15, $totals->subtotal->amount);
        self::assertSame(1, $totals->tax->amount);
        self::assertSame(16, $totals->total->amount);
    }

    #[Test]
    public function tax_rounds_half_away_from_zero_in_the_currency_minor_unit(): void
    {
        self::assertSame(309, (new FlatRateTax(825))->taxFor(Money::of(3750, Currency::USD), null)->amount);
        self::assertSame(250, (new FlatRateTax(1000))->taxFor(Money::of(2500, Currency::JPY), null)->amount);
        self::assertSame(1, (new FlatRateTax(5000))->taxFor(Money::of(1, Currency::USD), null)->amount);
    }

    #[Test]
    public function a_rate_outside_zero_to_one_hundred_percent_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FlatRateTax(10_001);
    }

    #[Test]
    public function a_line_needs_a_positive_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->line(100, 0);
    }
}
