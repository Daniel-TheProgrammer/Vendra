<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

use App\Modules\Payments\Domain\Settlement;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Money arrived: the gross amount the customer paid, of which the gateway
 * kept the fee.
 */
final readonly class PaymentCaptured extends PaymentEvent
{
    public function __construct(
        string $tenantId,
        string $paymentIntentId,
        string $orderId,
        public Money $amount,
        public Money $fee,
        public Settlement $settlement,
        CarbonImmutable $occurredAt,
    ) {
        parent::__construct($tenantId, $paymentIntentId, $orderId, $occurredAt);
    }

    public function eventName(): string
    {
        return 'payments.payment_captured';
    }

    public function payload(): array
    {
        return [
            ...parent::payload(),
            'currency' => $this->amount->currency->value,
            'amount_minor' => $this->amount->amount,
            'fee_minor' => $this->fee->amount,
            'settlement' => $this->settlement->value,
        ];
    }
}
