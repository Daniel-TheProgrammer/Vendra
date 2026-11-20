<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Replaces the application clock with one that only moves on demand.
     */
    protected function freezeClock(?CarbonImmutable $at = null): FrozenClock
    {
        $clock = new FrozenClock($at);
        $this->app->instance(Clock::class, $clock);

        return $clock;
    }
}
