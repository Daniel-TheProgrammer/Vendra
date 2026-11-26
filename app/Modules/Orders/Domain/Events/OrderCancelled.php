<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderCancelled extends OrderEvent
{
    public const NAME = 'orders.order_cancelled';

    public function eventName(): string
    {
        return self::NAME;
    }
}
