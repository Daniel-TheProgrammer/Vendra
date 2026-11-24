<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Queries;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindOrder
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $orderId): Order
    {
        $order = Order::query()->with('customer', 'lines')->whereKey($orderId)->first();

        return $order instanceof Order ? $order : throw EntityNotFound::withId('Order', $orderId);
    }
}
