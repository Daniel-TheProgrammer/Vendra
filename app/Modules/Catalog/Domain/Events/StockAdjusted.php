<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use Carbon\CarbonImmutable;

final readonly class StockAdjusted implements DomainEvent
{
    public function __construct(
        public string $tenantId,
        public string $variantId,
        public int $delta,
        public int $onHand,
        public int $available,
        public string $reason,
        public CarbonImmutable $occurredAt,
    ) {}

    public function eventName(): string
    {
        return 'catalog.stock_adjusted';
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
            'variant_id' => $this->variantId,
            'delta' => $this->delta,
            'on_hand' => $this->onHand,
            'available' => $this->available,
            'reason' => $this->reason,
        ];
    }
}
