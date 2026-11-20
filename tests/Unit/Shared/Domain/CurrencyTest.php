<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Domain;

use App\Shared\Domain\Money\Currency;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    #[Test]
    public function minor_units_follow_iso_4217(): void
    {
        self::assertSame(2, Currency::USD->minorUnit());
        self::assertSame(0, Currency::JPY->minorUnit());
        self::assertSame(3, Currency::KWD->minorUnit());
    }

    #[Test]
    public function subunit_factor_is_a_power_of_ten(): void
    {
        self::assertSame(100, Currency::EUR->subunitFactor());
        self::assertSame(1, Currency::JPY->subunitFactor());
        self::assertSame(1000, Currency::BHD->subunitFactor());
    }
}
