<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Illuminate\Console\Command;

/**
 * The long-running relay (the "outbox-relay" Compose service). Polls every
 * --sleep milliseconds while idle, and keeps going without pause while there
 * is work. Exits cleanly on SIGTERM or after --max-time, for the process
 * manager to restart it with fresh memory.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Drain what is due now, then exit}
        {--sleep=500 : Milliseconds to wait when there is nothing to publish}
        {--max-time=3600 : Seconds to run before exiting}';

    protected $description = 'Publish recorded domain events from the outbox';

    private bool $stopping = false;

    public function handle(OutboxRelay $relay): int
    {
        if ($this->option('once') === true) {
            $this->components->info(sprintf('%d event(s) published.', $relay->drain()));

            return self::SUCCESS;
        }

        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->stopping = true;
        });

        $sleep = max(50, (int) $this->option('sleep')) * 1000;
        $deadline = time() + max(1, (int) $this->option('max-time'));

        while (! $this->stopping && time() < $deadline) {
            if ($relay->relay() === 0) {
                usleep($sleep);
            }
        }

        return self::SUCCESS;
    }
}
