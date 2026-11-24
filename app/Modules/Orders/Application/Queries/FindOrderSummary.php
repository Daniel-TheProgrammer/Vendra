<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Queries;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindOrderSummary
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $orderId): OrderSummary
    {
        $order = Order::query()->whereKey($orderId)->first() ?? throw EntityNotFound::withId('Order', $orderId);

        return new OrderSummary(
            id: $order->id,
            number: $order->number,
            status: $order->status,
            subtotal: $order->money($order->subtotal),
            tax: $order->money($order->tax),
            total: $order->money($order->total),
            placedAt: $order->placed_at,
        );
    }
}
