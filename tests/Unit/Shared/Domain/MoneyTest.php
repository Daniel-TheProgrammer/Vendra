<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\CurrencyMismatch;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_adds_and_subtracts_amounts_in_the_same_currency(): void
    {
        $sum = Money::of(1050, Currency::USD)->add(Money::of(250, Currency::USD));
        $difference = $sum->subtract(Money::of(300, Currency::USD));

        self::assertSame(1300, $sum->amount);
        self::assertSame(1000, $difference->amount);
        self::assertSame(Currency::USD, $difference->currency);
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, Currency::USD)->add(Money::of(100, Currency::EUR));
    }

    #[Test]
    public function comparison_across_currencies_is_also_refused(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, Currency::GBP)->greaterThan(Money::of(1, Currency::NGN));
    }

    #[Test]
    public function equals_compares_amount_and_currency(): void
    {
        self::assertTrue(Money::of(100, Currency::USD)->equals(Money::of(100, Currency::USD)));
        self::assertFalse(Money::of(100, Currency::USD)->equals(Money::of(100, Currency::EUR)));
        self::assertFalse(Money::of(100, Currency::USD)->equals(Money::of(101, Currency::USD)));
    }

    #[Test]
    public function it_allocates_without_losing_a_minor_unit(): void
    {
        $parts = Money::of(100, Currency::USD)->allocate(1, 1, 1);

        self::assertSame([34, 33, 33], array_map(fn (Money $m): int => $m->amount, $parts));
    }

    #[Test]
    public function it_allocates_by_unequal_ratios(): void
    {
        $parts = Money::of(1000, Currency::EUR)->allocate(70, 30);

        self::assertSame([700, 300], array_map(fn (Money $m): int => $m->amount, $parts));
    }

    #[Test]
    public function it_allocates_negative_amounts_symmetrically(): void
    {
        $parts = Money::of(-100, Currency::USD)->allocate(1, 1, 1);

        self::assertSame([-34, -33, -33], array_map(fn (Money $m): int => $m->amount, $parts));
    }

    #[Test]
    public function zero_ratio_parts_receive_nothing(): void
    {
        $parts = Money::of(101, Currency::USD)->allocate(0, 1, 1);

        self::assertSame([0, 51, 50], array_map(fn (Money $m): int => $m->amount, $parts));
    }

    #[Test]
    public function it_rejects_degenerate_ratios(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, Currency::USD)->allocate(0, 0);
    }

    #[Test]
    public function it_allocates_evenly(): void
    {
        $parts = Money::of(7, Currency::JPY)->allocateEvenly(4);

        self::assertSame([2, 2, 2, 1], array_map(fn (Money $m): int => $m->amount, $parts));
        self::assertSame(7, array_sum(array_map(fn (Money $m): int => $m->amount, $parts)));
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function ratioCases(): iterable
    {
        yield '8.25% of 100.00' => [10000, 825, 10000, 825];
        yield 'exact half rounds away from zero' => [5, 1, 2, 3];
        yield 'negative half rounds away from zero' => [-5, 1, 2, -3];
        yield 'below half rounds down' => [7, 1, 3, 2];
        yield 'above half rounds up' => [8, 2, 3, 5];
    }

    #[Test]
    #[DataProvider('ratioCases')]
    public function it_applies_ratios_with_half_away_from_zero_rounding(int $amount, int $num, int $den, int $expected): void
    {
        self::assertSame($expected, Money::of($amount, Currency::USD)->ratio($num, $den)->amount);
    }

    #[Test]
    public function it_rejects_a_zero_denominator(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1, Currency::USD)->ratio(1, 0);
    }

    /**
     * @return iterable<string, array{string, Currency, int}>
     */
    public static function decimalCases(): iterable
    {
        yield 'two decimals' => ['12.34', Currency::USD, 1234];
        yield 'one decimal is padded' => ['12.3', Currency::USD, 1230];
        yield 'no decimals' => ['12', Currency::USD, 1200];
        yield 'negative' => ['-0.05', Currency::USD, -5];
        yield 'zero-decimal currency' => ['1500', Currency::JPY, 1500];
        yield 'three-decimal currency' => ['1.005', Currency::KWD, 1005];
    }

    #[Test]
    #[DataProvider('decimalCases')]
    public function it_parses_decimal_strings(string $decimal, Currency $currency, int $expected): void
    {
        self::assertSame($expected, Money::fromDecimal($decimal, $currency)->amount);
    }

    #[Test]
    public function it_rejects_decimals_with_too_many_places(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal('1.234', Currency::USD);
    }

    #[Test]
    public function it_formats_decimals_and_strings(): void
    {
        self::assertSame('12.34', Money::of(1234, Currency::USD)->toDecimal());
        self::assertSame('-0.05', Money::of(-5, Currency::USD)->toDecimal());
        self::assertSame('1500', Money::of(1500, Currency::JPY)->toDecimal());
        self::assertSame('1.005', Money::of(1005, Currency::KWD)->toDecimal());
        self::assertSame('EUR 0.99', (string) Money::of(99, Currency::EUR));
    }

    #[Test]
    public function it_serialises_to_json_as_minor_units_and_currency(): void
    {
        self::assertSame('{"amount":1234,"currency":"USD"}', json_encode(Money::of(1234, Currency::USD)));
    }

    #[Test]
    public function sign_helpers_behave(): void
    {
        $money = Money::of(-10, Currency::USD);

        self::assertTrue($money->isNegative());
        self::assertFalse($money->isPositive());
        self::assertFalse($money->isZero());
        self::assertSame(10, $money->abs()->amount);
        self::assertSame(10, $money->negate()->amount);
        self::assertTrue(Money::zero(Currency::USD)->isZero());
        self::assertSame(-30, $money->multiply(3)->amount);
    }
}
