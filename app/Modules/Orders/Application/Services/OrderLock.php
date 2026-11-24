<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Exception\VersionMismatch;

/**
 * Loads an order with a FOR UPDATE row lock. Every write to an order goes
 * through here inside a transaction: the lock serialises writers, and the
 * version check (the client's If-Match) rejects a change decided on a copy
 * that is no longer current. Together they make "check then change" safe.
 */
final readonly class OrderLock
{
    /**
     * @throws EntityNotFound
     * @throws VersionMismatch
     */
    public function acquire(string $orderId, ?int $expectedVersion = null): Order
    {
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

        if (! $order instanceof Order) {
            throw EntityNotFound::withId('Order', $orderId);
        }

        if ($expectedVersion !== null && $expectedVersion !== $order->version) {
            throw VersionMismatch::of('order', $expectedVersion, $order->version);
        }

        return $order;
    }
}
