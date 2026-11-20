<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use Carbon\CarbonImmutable;

/**
 * Something that happened in the domain and that other modules may react to.
 *
 * Events are immutable facts. The payload must be plain scalars and arrays so it
 * can be stored in the outbox and delivered to webhook subscribers unchanged.
 */
interface DomainEvent
{
    /**
     * Dot-separated, versionable name such as "orders.order_placed".
     */
    public function eventName(): string;

    public function tenantId(): ?string;

    public function occurredAt(): CarbonImmutable;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
