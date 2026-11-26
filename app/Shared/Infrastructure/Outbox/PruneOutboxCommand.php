<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Clock\Clock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Deletes published events past their retention (outbox.retention_days).
 * Dead messages are kept until someone has looked at them.
 */
final class PruneOutboxCommand extends Command
{
    protected $signature = 'outbox:prune';

    protected $description = 'Delete published outbox messages past their retention';

    public function handle(ConnectionResolverInterface $connections, Clock $clock, Config $config): int
    {
        $cutoff = $clock->now()->subDays($config->integer('outbox.retention_days', 30))->utc()->format('Y-m-d H:i:s.uP');
        $deleted = 0;

        do {
            $batch = $connections->connection()->delete(
                'DELETE FROM outbox_messages WHERE id IN (SELECT id FROM outbox_messages WHERE published_at < ? LIMIT 5000)',
                [$cutoff],
            );
            $deleted += $batch;
        } while ($batch > 0);

        $this->components->info(sprintf('%d published event(s) pruned.', $deleted));

        return self::SUCCESS;
    }
}
