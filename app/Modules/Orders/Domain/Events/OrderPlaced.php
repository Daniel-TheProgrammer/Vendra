<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderPlaced extends OrderEvent
{
    public const NAME = 'orders.order_placed';

    public function eventName(): string
    {
        return self::NAME;
    }
}
