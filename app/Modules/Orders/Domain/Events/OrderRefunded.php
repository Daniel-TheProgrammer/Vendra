<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderRefunded extends OrderEvent
{
    public const NAME = 'orders.order_refunded';

    public function eventName(): string
    {
        return self::NAME;
    }
}
