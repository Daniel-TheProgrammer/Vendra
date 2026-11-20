<?php

declare(strict_types=1);

namespace App\Shared\Application\Clock;

use Carbon\CarbonImmutable;
use DateInterval;

/**
 * A clock that only moves when told to. For tests.
 */
final class FrozenClock implements Clock
{
    private CarbonImmutable $now;

    public function __construct(?CarbonImmutable $now = null)
    {
        $this->now = $now ?? CarbonImmutable::now();
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function set(CarbonImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(DateInterval|string $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}
