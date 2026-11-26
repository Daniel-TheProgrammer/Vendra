<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\StoredPayload;
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

    public const NAME = 'catalog.stock_adjusted';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new self(
            tenantId: $tenantId,
            variantId: $stored->string('variant_id'),
            delta: $stored->int('delta'),
            onHand: $stored->int('on_hand'),
            available: $stored->int('available'),
            reason: $stored->string('reason'),
            occurredAt: $occurredAt,
        );
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
