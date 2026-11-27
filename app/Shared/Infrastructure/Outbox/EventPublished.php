<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Carbon\CarbonImmutable;

/**
 * The outbox envelope of a published event: its id and its stored form.
 * The relay dispatches it right after the domain event itself, in the same
 * savepoint. Consumers that forward events as they are (webhook delivery,
 * GET /events) listen to this instead of to every domain event class.
 */
final readonly class EventPublished
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $type,
        public array $payload,
        public CarbonImmutable $occurredAt,
    ) {}
}
