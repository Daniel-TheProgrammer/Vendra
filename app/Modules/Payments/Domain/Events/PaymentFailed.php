<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

use App\Shared\Domain\Event\StoredPayload;
use Carbon\CarbonImmutable;

final readonly class PaymentFailed extends PaymentEvent
{
    public function __construct(
        string $tenantId,
        string $paymentIntentId,
        string $orderId,
        public ?string $failureCode,
        CarbonImmutable $occurredAt,
    ) {
        parent::__construct($tenantId, $paymentIntentId, $orderId, $occurredAt);
    }

    public const NAME = 'payments.payment_failed';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new self($tenantId, $stored->string('payment_intent_id'), $stored->string('order_id'), $stored->nullableString('failure_code'), $occurredAt);
    }

    public function payload(): array
    {
        return [...parent::payload(), 'failure_code' => $this->failureCode];
    }
}
