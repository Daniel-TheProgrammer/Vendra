<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class OrderNotPayable extends ConflictException
{
    public static function inStatus(string $orderId, string $status): self
    {
        return new self(sprintf('Order %s is %s; only a pending or awaiting_payment order can be paid.', $orderId, $status));
    }

    public static function free(string $orderId): self
    {
        return new self(sprintf('Order %s totals zero; there is nothing to charge.', $orderId));
    }

    public function type(): string
    {
        return 'order-not-payable';
    }

    public function title(): string
    {
        return 'The order cannot be paid';
    }
}
