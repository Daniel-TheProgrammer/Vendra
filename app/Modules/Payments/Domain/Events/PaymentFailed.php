<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

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

    public function eventName(): string
    {
        return 'payments.payment_failed';
    }

    public function payload(): array
    {
        return [...parent::payload(), 'failure_code' => $this->failureCode];
    }
}
