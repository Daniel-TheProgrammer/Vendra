<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderPlaced extends OrderEvent
{
    public function eventName(): string
    {
        return 'orders.order_placed';
    }
}
