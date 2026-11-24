<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class IllegalTransition extends ConflictException
{
    private OrderStatus $from;

    private OrderStatus $to;

    public static function between(OrderStatus $from, OrderStatus $to): self
    {
        $exception = new self(sprintf('An order that is %s cannot become %s.', $from->value, $to->value));
        $exception->from = $from;
        $exception->to = $to;

        return $exception;
    }

    public function type(): string
    {
        return 'illegal-transition';
    }

    public function title(): string
    {
        return 'Not allowed in the order\'s current status';
    }

    /**
     * @return array{status: string, attempted: string, allowed: list<string>}
     */
    public function extensions(): array
    {
        return [
            'status' => $this->from->value,
            'attempted' => $this->to->value,
            'allowed' => array_map(static fn (OrderStatus $status): string => $status->value, $this->from->allowedTransitions()),
        ];
    }
}
