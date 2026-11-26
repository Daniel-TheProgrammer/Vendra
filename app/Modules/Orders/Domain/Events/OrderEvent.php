<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\StoredPayload;
use Carbon\CarbonImmutable;

/**
 * Order events name the order and its new version; consumers (ledger,
 * webhooks, search) read the current state themselves.
 *
 * Subclasses add nothing but a name, so one constructor rebuilds them all.
 *
 * @phpstan-consistent-constructor
 */
abstract readonly class OrderEvent implements DomainEvent
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public int $version,
        public CarbonImmutable $occurredAt,
    ) {}

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): array
    {
        return ['order_id' => $this->orderId, 'version' => $this->version];
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new static($tenantId, $stored->string('order_id'), $stored->int('version'), $occurredAt);
    }
}
