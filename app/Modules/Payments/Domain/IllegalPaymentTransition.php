<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class IllegalPaymentTransition extends ConflictException
{
    private PaymentIntentStatus $from;

    private PaymentIntentStatus $to;

    public static function between(PaymentIntentStatus $from, PaymentIntentStatus $to): self
    {
        $exception = new self(sprintf('A payment that is %s cannot become %s.', $from->value, $to->value));
        $exception->from = $from;
        $exception->to = $to;

        return $exception;
    }

    public function type(): string
    {
        return 'illegal-payment-transition';
    }

    public function title(): string
    {
        return 'Not allowed in the payment\'s current status';
    }

    /**
     * @return array{status: string, attempted: string, allowed: list<string>}
     */
    public function extensions(): array
    {
        return [
            'status' => $this->from->value,
            'attempted' => $this->to->value,
            'allowed' => array_map(static fn (PaymentIntentStatus $status): string => $status->value, $this->from->allowedTransitions()),
        ];
    }
}
