<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

use App\Shared\Domain\Event\DomainEvent;
use Carbon\CarbonImmutable;

/**
 * Payment events carry the money facts their consumers need (the ledger
 * books them as they are), so nobody reads Payments' tables to react.
 */
abstract readonly class PaymentEvent implements DomainEvent
{
    public function __construct(
        public string $tenantId,
        public string $paymentIntentId,
        public string $orderId,
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
        return ['payment_intent_id' => $this->paymentIntentId, 'order_id' => $this->orderId];
    }
}
