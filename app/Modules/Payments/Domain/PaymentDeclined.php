<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The gateway refused the money: the intent is now failed, and the order
 * waits for a new attempt.
 */
final class PaymentDeclined extends DomainException
{
    private string $paymentIntentId = '';

    private ?string $declineCode = null;

    public static function forIntent(string $paymentIntentId, ?string $declineCode, ?string $message): self
    {
        $exception = new self($message !== null && $message !== '' ? $message : 'The payment was declined.');
        $exception->paymentIntentId = $paymentIntentId;
        $exception->declineCode = $declineCode;

        return $exception;
    }

    public function status(): int
    {
        return 402;
    }

    public function type(): string
    {
        return 'payment-declined';
    }

    public function title(): string
    {
        return 'Payment declined';
    }

    /**
     * @return array{payment_intent_id: string, decline_code: string|null}
     */
    public function extensions(): array
    {
        return ['payment_intent_id' => $this->paymentIntentId, 'decline_code' => $this->declineCode];
    }
}
