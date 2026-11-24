<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class ShippingAddressLocked extends ConflictException
{
    private string $status = '';

    public static function for(OrderStatus $status): self
    {
        $exception = new self(sprintf('The shipping address of a %s order can no longer change.', $status->value));
        $exception->status = $status->value;

        return $exception;
    }

    public function type(): string
    {
        return 'shipping-address-locked';
    }

    public function title(): string
    {
        return 'Shipping address can no longer change';
    }

    /**
     * @return array{status: string}
     */
    public function extensions(): array
    {
        return ['status' => $this->status];
    }
}
