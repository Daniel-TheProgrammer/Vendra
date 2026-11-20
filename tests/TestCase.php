<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\PendingCommand;

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

    /**
     * Typed variant of artisan() for fluent console assertions.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            self::fail('Console output must be mocked to assert on a command; do not call withoutMockingConsoleOutput().');
        }

        return $pending;
    }
}
