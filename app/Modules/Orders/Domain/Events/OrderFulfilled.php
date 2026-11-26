<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderFulfilled extends OrderEvent
{
    public const NAME = 'orders.order_fulfilled';

    public function eventName(): string
    {
        return self::NAME;
    }
}
