<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class IllegalRefundTransition extends ConflictException
{
    private RefundStatus $from;

    private RefundStatus $to;

    public static function between(RefundStatus $from, RefundStatus $to): self
    {
        $exception = new self(sprintf('A refund that is %s cannot become %s.', $from->value, $to->value));
        $exception->from = $from;
        $exception->to = $to;

        return $exception;
    }

    public function type(): string
    {
        return 'illegal-refund-transition';
    }

    public function title(): string
    {
        return 'Not allowed in the refund\'s current status';
    }

    /**
     * @return array{status: string, attempted: string, allowed: list<string>}
     */
    public function extensions(): array
    {
        return [
            'status' => $this->from->value,
            'attempted' => $this->to->value,
            'allowed' => array_map(static fn (RefundStatus $status): string => $status->value, $this->from->allowedTransitions()),
        ];
    }
}
