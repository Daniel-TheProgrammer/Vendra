<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Event\DomainEvent;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Writes events to outbox_messages on the caller's connection, inside the
 * caller's transaction: if the business change rolls back, so do its events.
 *
 * Once the transaction commits, the relay is nudged (config outbox.after_commit)
 * so listeners run within milliseconds; the outbox:relay process publishes
 * anything the nudge missed.
 */
final readonly class OutboxRecorder implements EventRecorder
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private EventRegistry $registry,
        private Clock $clock,
        private Config $config,
        private Container $container,
    ) {}

    public function record(DomainEvent ...$events): void
    {
        if ($events === []) {
            return;
        }

        $connection = $this->connections->connection();

        if (! $connection instanceof Connection) {
            throw new LogicException('The outbox needs a database connection that supports after-commit callbacks.');
        }

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Domain events are recorded inside the transaction that causes them (ADR 0010); start one with TransactionRunner.');
        }

        $now = $this->clock->now()->utc()->format('Y-m-d H:i:s.uP');
        $rows = [];

        foreach ($events as $event) {
            // Fail now, in the request, rather than in the relay later.
            $this->registry->classOf($event->eventName());

            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $event->tenantId(),
                'event_type' => $event->eventName(),
                'payload' => json_encode($event->payload(), JSON_THROW_ON_ERROR),
                'occurred_at' => $event->occurredAt()->utc()->format('Y-m-d H:i:s.uP'),
                'recorded_at' => $now,
                'available_at' => $now,
            ];
        }

        $connection->table('outbox_messages')->insert($rows);
        $connection->afterCommit(fn () => $this->nudge());
    }

    private function nudge(): void
    {
        match ($this->config->get('outbox.after_commit')) {
            'queue' => PublishOutbox::dispatch(),
            'inline' => $this->container->make(OutboxRelay::class)->drain(),
            default => null,
        };
    }
}
