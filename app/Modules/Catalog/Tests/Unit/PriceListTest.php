<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Unit;

use App\Modules\Catalog\Domain\Price;
use App\Modules\Catalog\Domain\PriceList;
use App\Modules\Catalog\Domain\PriceWindow;
use App\Modules\Catalog\Domain\PriceWindowOverlaps;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceListTest extends TestCase
{
    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }

    private function price(int $minor, Currency $currency = Currency::USD, ?string $from = null, ?string $to = null, ?string $id = null): Price
    {
        return new Price(
            Money::of($minor, $currency),
            new PriceWindow($from !== null ? self::at($from) : null, $to !== null ? self::at($to) : null),
            $id,
        );
    }

    private function list(): PriceList
    {
        return new PriceList([
            $this->price(2000),
            $this->price(1500, from: '2026-11-27T00:00:00Z', to: '2026-12-01T00:00:00Z', id: 'black-friday'),
            $this->price(2200, from: '2027-01-01T00:00:00Z', id: 'new-year-increase'),
            $this->price(1800, Currency::EUR),
            $this->price(900, Currency::GBP, from: '2026-11-27T00:00:00Z', to: '2026-12-01T00:00:00Z', id: 'uk-only-sale'),
        ]);
    }

    #[Test]
    public function the_base_price_applies_when_no_window_does(): void
    {
        self::assertSame(2000, $this->list()->effectiveAt(Currency::USD, self::at('2026-11-23T12:00:00Z'))?->amount->amount);
    }

    #[Test]
    public function a_running_window_beats_the_base_price_and_stops_at_its_end(): void
    {
        $list = $this->list();

        self::assertSame('black-friday', $list->effectiveAt(Currency::USD, self::at('2026-11-27T00:00:00Z'))?->id);
        self::assertNull($list->effectiveAt(Currency::USD, self::at('2026-12-01T00:00:00Z'))?->id, 'back to the base price');
        self::assertSame('new-year-increase', $list->effectiveAt(Currency::USD, self::at('2030-06-01T00:00:00Z'))?->id);
    }

    #[Test]
    public function without_a_base_price_a_currency_is_only_sellable_inside_its_windows(): void
    {
        $list = $this->list();

        self::assertNull($list->effectiveAt(Currency::GBP, self::at('2026-11-23T00:00:00Z')));
        self::assertSame(900, $list->effectiveAt(Currency::GBP, self::at('2026-11-28T00:00:00Z'))?->amount->amount);
        self::assertNull($list->effectiveAt(Currency::JPY, self::at('2026-11-28T00:00:00Z')));
    }

    #[Test]
    public function every_currency_resolves_independently(): void
    {
        $effective = $this->list()->allEffectiveAt(self::at('2026-11-28T00:00:00Z'));

        self::assertSame(
            ['EUR' => 1800, 'GBP' => 900, 'USD' => 1500],
            array_combine(
                array_map(static fn (Price $price): string => $price->amount->currency->value, $effective),
                array_map(static fn (Price $price): int => $price->amount->amount, $effective),
            ),
        );
    }

    #[Test]
    public function upcoming_scheduled_prices_exclude_base_prices_and_ended_windows(): void
    {
        $ids = static fn (PriceList $list, string $at): array => array_map(static fn (Price $price): ?string => $price->id, $list->upcomingScheduledAt(self::at($at)));

        // Soonest first; same start, then by currency code.
        self::assertSame(['uk-only-sale', 'black-friday', 'new-year-increase'], $ids($this->list(), '2026-11-23T00:00:00Z'));
        self::assertSame(['new-year-increase'], $ids($this->list(), '2026-12-01T00:00:00Z'));
    }

    #[Test]
    public function an_overlapping_window_in_the_same_currency_is_refused(): void
    {
        try {
            $this->list()->assertCanSchedule($this->price(1400, from: '2026-11-30T00:00:00Z', to: '2026-12-02T00:00:00Z'));
            self::fail('The overlap must be refused.');
        } catch (PriceWindowOverlaps $exception) {
            self::assertSame(409, $exception->status());
            self::assertSame('black-friday', $exception->extensions()['conflicting_price_id']);
        }
    }

    #[Test]
    public function back_to_back_windows_other_currencies_and_base_prices_do_not_conflict(): void
    {
        $list = $this->list();

        $list->assertCanSchedule($this->price(1700, from: '2026-12-01T00:00:00Z', to: '2027-01-01T00:00:00Z'));
        $list->assertCanSchedule($this->price(1300, Currency::EUR, from: '2026-11-27T00:00:00Z', to: '2026-12-01T00:00:00Z'));
        $list->assertCanSchedule($this->price(1, Currency::USD, to: '2026-11-27T00:00:00Z'));

        $this->addToAssertionCount(3);
    }
}
