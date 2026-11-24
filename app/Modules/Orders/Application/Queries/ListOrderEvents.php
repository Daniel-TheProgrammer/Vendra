<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Queries;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderEventRecord;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Database\Eloquent\Collection;

/**
 * An order's full history, oldest first. Orders have a handful of entries,
 * so there is no pagination.
 */
final readonly class ListOrderEvents
{
    /**
     * @return Collection<int, OrderEventRecord>
     *
     * @throws EntityNotFound
     */
    public function __invoke(string $orderId): Collection
    {
        if (! Order::query()->whereKey($orderId)->exists()) {
            throw EntityNotFound::withId('Order', $orderId);
        }

        return OrderEventRecord::query()->where('order_id', $orderId)->orderBy('id')->get();
    }
}
