<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use Carbon\CarbonImmutable;

/**
 * Catalog events carry identifiers and the new version only. Consumers
 * (search indexing, webhooks) read the current state themselves, so a late
 * delivery never applies stale data.
 */
abstract readonly class ProductEvent implements DomainEvent
{
    public function __construct(
        public string $tenantId,
        public string $productId,
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
        return [
            'product_id' => $this->productId,
            'version' => $this->version,
        ];
    }
}
