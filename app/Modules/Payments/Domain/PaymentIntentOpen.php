<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;

/**
 * An order has at most one payment in flight. The client finishes or
 * cancels that one rather than starting a second charge.
 */
final class PaymentIntentOpen extends ConflictException
{
    private string $paymentIntentId = '';

    public static function forOrder(string $orderId, string $paymentIntentId): self
    {
        $exception = new self(sprintf('Order %s already has payment %s in progress.', $orderId, $paymentIntentId));
        $exception->paymentIntentId = $paymentIntentId;

        return $exception;
    }

    public function type(): string
    {
        return 'payment-intent-open';
    }

    public function title(): string
    {
        return 'A payment is already in progress';
    }

    /**
     * @return array{payment_intent_id: string}
     */
    public function extensions(): array
    {
        return ['payment_intent_id' => $this->paymentIntentId];
    }
}
