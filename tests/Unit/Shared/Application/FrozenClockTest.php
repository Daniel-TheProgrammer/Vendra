<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    #[Test]
    public function it_does_not_move_on_its_own(): void
    {
        $at = CarbonImmutable::parse('2026-01-01T10:00:00Z');
        $clock = new FrozenClock($at);

        self::assertTrue($clock->now()->equalTo($at));
        self::assertTrue($clock->now()->equalTo($at));
    }

    #[Test]
    public function it_advances_and_can_be_set(): void
    {
        $clock = new FrozenClock(CarbonImmutable::parse('2026-01-01T10:00:00Z'));

        $clock->advance('PT90M');
        self::assertSame('2026-01-01T11:30:00+00:00', $clock->now()->toIso8601String());

        $clock->set(CarbonImmutable::parse('2030-06-15T00:00:00Z'));
        self::assertSame('2030-06-15', $clock->now()->toDateString());
    }
}
