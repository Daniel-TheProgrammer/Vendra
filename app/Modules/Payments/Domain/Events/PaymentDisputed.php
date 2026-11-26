<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

use App\Shared\Domain\Event\StoredPayload;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The customer's bank has disputed the payment. The provider withholds the
 * amount while the dispute runs; the merchant answers it in the provider's
 * dashboard.
 */
final readonly class PaymentDisputed extends PaymentEvent
{
    public function __construct(
        string $tenantId,
        string $paymentIntentId,
        string $orderId,
        public Money $amount,
        public ?string $reason,
        CarbonImmutable $occurredAt,
    ) {
        parent::__construct($tenantId, $paymentIntentId, $orderId, $occurredAt);
    }

    public const NAME = 'payments.payment_disputed';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new self($tenantId, $stored->string('payment_intent_id'), $stored->string('order_id'), $stored->money('amount'), $stored->nullableString('reason'), $occurredAt);
    }

    public function payload(): array
    {
        return [
            ...parent::payload(),
            'currency' => $this->amount->currency->value,
            'amount_minor' => $this->amount->amount,
            'reason' => $this->reason,
        ];
    }
}
