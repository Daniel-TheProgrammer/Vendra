<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes recorded events to the event bus (ADR 0010).
 *
 * A pass takes a batch of due messages FOR UPDATE SKIP LOCKED, so any number
 * of relays can run: each message is taken by exactly one of them. Each
 * message is published in its own savepoint, in its tenant: its listeners'
 * database writes and the published mark commit together, so a listener that
 * only writes to the database runs exactly once. Anything else a listener
 * does (a queued job, a call out) may happen again if the pass dies before
 * it commits: delivery is at least once, and listeners are idempotent.
 *
 * A message whose listeners fail is retried with backoff (outbox.backoff),
 * then marked dead and logged as critical. Messages are taken oldest first,
 * but a failed one does not hold back those behind it, and concurrent relays
 * interleave: listeners must not rely on order (ADR 0010).
 */
final class OutboxRelay
{
    private bool $draining = false;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly EventRegistry $registry,
        private readonly TenantContext $tenant,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * One pass.
     *
     * @return int messages published
     */
    public function relay(?int $limit = null): int
    {
        $connection = $this->connection();
        $limit ??= $this->config->integer('outbox.batch_size', 100);

        return $connection->transaction(function () use ($connection, $limit): int {
            /** @var list<object{id: string, tenant_id: string, event_type: string, payload: string, occurred_at: string, attempts: int}> $due */
            $due = $connection->select(
                'SELECT id, tenant_id, event_type, payload, occurred_at, attempts FROM outbox_messages
                 WHERE published_at IS NULL AND dead_at IS NULL AND available_at <= ?
                 ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED',
                [$this->now(), $limit],
            );

            $published = 0;

            foreach ($due as $message) {
                try {
                    $connection->transaction(function () use ($connection, $message): void {
                        $event = $this->rebuild($message->tenant_id, $message->event_type, $message->occurred_at, $message->payload);
                        $envelope = new EventPublished($message->id, $message->tenant_id, $message->event_type, $event->payload(), $event->occurredAt());

                        $this->tenant->runAs($message->tenant_id, function () use ($event, $envelope): void {
                            $this->events()->dispatch($event);
                            $this->events()->dispatch($envelope);
                        });
                        $connection->update('UPDATE outbox_messages SET published_at = ?, last_error = NULL WHERE id = ?', [$this->now(), $message->id]);
                    });
                    $published++;
                } catch (Throwable $failure) {
                    $this->failed($connection, $message->id, $message->event_type, $message->attempts + 1, $failure);
                }
            }

            return $published;
        });
    }

    /**
     * Passes until nothing is left to publish now. Re-entrant calls (a
     * listener's own transaction nudging the relay) return at once: the
     * outer loop picks their events up on its next pass.
     */
    public function drain(): int
    {
        if ($this->draining) {
            return 0;
        }

        $this->draining = true;
        $total = 0;

        try {
            do {
                $published = $this->relay();
                $total += $published;
            } while ($published > 0);
        } finally {
            $this->draining = false;
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    public function rebuild(string $tenantId, string $type, string $occurredAt, array|string $payload): DomainEvent
    {
        $class = $this->registry->classOf($type);
        $decoded = is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : $payload;

        /** @var array<string, mixed> $decoded */
        return $class::fromStored($tenantId, CarbonImmutable::parse($occurredAt), is_array($decoded) ? $decoded : []);
    }

    private function failed(Connection $connection, string $id, string $type, int $attempts, Throwable $failure): void
    {
        /** @var list<int> $backoff */
        $backoff = (array) $this->config->get('outbox.backoff', []);
        $dead = $attempts > count($backoff);
        $error = mb_substr($failure::class.': '.$failure->getMessage(), 0, 2000);

        $connection->update(
            'UPDATE outbox_messages SET attempts = ?, last_error = ?, available_at = ?, dead_at = ? WHERE id = ?',
            [
                $attempts,
                $error,
                $dead ? $this->now() : $this->clock->now()->addSeconds($backoff[$attempts - 1] ?? 0)->utc()->format('Y-m-d H:i:s.uP'),
                $dead ? $this->now() : null,
                $id,
            ],
        );

        $context = ['outbox_message_id' => $id, 'event_type' => $type, 'attempts' => $attempts, 'error' => $error];

        if ($dead) {
            $this->logger->critical('An event could not be published and was given up on.', $context);

            return;
        }

        $this->logger->warning('An event\'s listeners failed; it will be retried.', $context);
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return $this->connections->connection();
    }

    /**
     * Resolved per use, so a faked event bus (tests) is honoured.
     */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    private function now(): string
    {
        return $this->clock->now()->utc()->format('Y-m-d H:i:s.uP');
    }
}
