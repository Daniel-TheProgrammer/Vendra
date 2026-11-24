<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderEventRecord;

/**
 * Appends to an order's history, in the same transaction as the change it
 * describes.
 */
final readonly class OrderHistory
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(Order $order, string $type, ?OrderStatus $from, ?string $actorId = null, array $data = []): void
    {
        OrderEventRecord::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'from_status' => $from,
            'to_status' => $order->status,
            'data' => $data,
            'actor_id' => $actorId,
        ]);
    }
}
