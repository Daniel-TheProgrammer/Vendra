<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Loads an order with a FOR UPDATE row lock. Every write to an order goes
 * through here inside a transaction, so status checks and the changes that
 * depend on them cannot interleave with another writer.
 */
final readonly class OrderLock
{
    /**
     * @throws EntityNotFound
     */
    public function acquire(string $orderId): Order
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        return $order instanceof Order ? $order : throw EntityNotFound::withId('Order', $orderId);
    }
}
