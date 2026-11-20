<?php

declare(strict_types=1);

namespace App\Shared\Application\Clock;

use Carbon\CarbonImmutable;

/**
 * The only sanctioned way to read the current time in application and domain code.
 * Inject it instead of calling now(), so tests can freeze and advance time.
 */
interface Clock
{
    public function now(): CarbonImmutable;
}
