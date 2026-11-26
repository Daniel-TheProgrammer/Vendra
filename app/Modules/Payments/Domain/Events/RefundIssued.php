<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Events;

use App\Modules\Payments\Domain\Settlement;
use App\Shared\Domain\Event\StoredPayload;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Money went back to the customer. previouslyRefunded is what earlier
 * refunds of the same payment returned, so consumers can split each refund
 * consistently (the ledger allocates tax cumulatively: the refunds of a
 * payment add up to exactly its tax).
 */
final readonly class RefundIssued extends PaymentEvent
{
    public function __construct(
        string $tenantId,
        string $paymentIntentId,
        string $orderId,
        public string $refundId,
        public Money $amount,
        public Money $previouslyRefunded,
        public Settlement $settlement,
        CarbonImmutable $occurredAt,
    ) {
        parent::__construct($tenantId, $paymentIntentId, $orderId, $occurredAt);
    }

    public const NAME = 'payments.refund_issued';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new self(
            $tenantId,
            $stored->string('payment_intent_id'),
            $stored->string('order_id'),
            $stored->string('refund_id'),
            $stored->money('amount'),
            $stored->money('previously_refunded'),
            Settlement::from($stored->string('settlement')),
            $occurredAt,
        );
    }

    public function payload(): array
    {
        return [
            ...parent::payload(),
            'refund_id' => $this->refundId,
            'currency' => $this->amount->currency->value,
            'amount_minor' => $this->amount->amount,
            'previously_refunded_minor' => $this->previouslyRefunded->amount,
            'settlement' => $this->settlement->value,
        ];
    }
}
