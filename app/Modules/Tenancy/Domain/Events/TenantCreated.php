<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\StoredPayload;
use Carbon\CarbonImmutable;

final readonly class TenantCreated implements DomainEvent
{
    public function __construct(
        public string $tenantId,
        public string $slug,
        public CarbonImmutable $occurredAt,
    ) {}

    public const NAME = 'tenancy.tenant_created';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        return new self($tenantId, (new StoredPayload($payload))->string('slug'), $occurredAt);
    }

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
        return [
            'tenant_id' => $this->tenantId,
            'slug' => $this->slug,
        ];
    }
}
