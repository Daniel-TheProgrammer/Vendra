<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;
use App\Shared\Domain\Money\Money;

/**
 * Only money that was taken can be given back, and no more of it than was
 * taken.
 */
final class RefundNotAllowed extends ConflictException
{
    private ?string $refundable = null;

    public static function notCaptured(string $intentId, PaymentIntentStatus $status): self
    {
        return new self(sprintf('Payment %s is %s; only a succeeded payment can be refunded.', $intentId, $status->value));
    }

    public static function exceeds(string $intentId, Money $requested, Money $refundable): self
    {
        $exception = new self(sprintf('Payment %s has %s %s left to refund; %s was asked for.', $intentId, $refundable->toDecimal(), $refundable->currency->value, $requested->toDecimal()));
        $exception->refundable = $refundable->toDecimal();

        return $exception;
    }

    public function type(): string
    {
        return 'refund-not-allowed';
    }

    public function title(): string
    {
        return 'The payment cannot be refunded';
    }

    /**
     * @return array<string, string>
     */
    public function extensions(): array
    {
        return $this->refundable !== null ? ['refundable' => $this->refundable] : [];
    }
}
