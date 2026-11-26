<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * The nudge queued after a transaction that recorded events commits: a
 * worker drains the outbox at once instead of waiting for the relay's next
 * poll. Losing one costs latency, never an event.
 */
final class PublishOutbox implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function handle(OutboxRelay $relay): void
    {
        $relay->drain();
    }
}
