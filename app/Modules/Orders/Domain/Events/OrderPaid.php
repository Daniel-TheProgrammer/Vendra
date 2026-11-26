<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain\Events;

final readonly class OrderPaid extends OrderEvent
{
    public const NAME = 'orders.order_paid';

    public function eventName(): string
    {
        return self::NAME;
    }
}
