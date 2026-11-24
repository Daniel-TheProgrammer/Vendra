<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Orders\Application\Services\OrderHistory;
use App\Modules\Orders\Application\Services\OrderLock;
use App\Modules\Orders\Domain\ShippingAddress;
use App\Modules\Orders\Domain\ShippingAddressLocked;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Changes where an order ships to, until it ships. Totals are not
 * recomputed: the tax charged was agreed when the order was placed.
 */
final readonly class UpdateShippingAddress
{
    public function __construct(
        private TransactionRunner $transaction,
        private OrderLock $lock,
        private OrderHistory $history,
    ) {}

    /**
     * @throws ShippingAddressLocked
     */
    public function __invoke(string $orderId, ShippingAddress $address, ?string $actorId = null): Order
    {
        return $this->transaction->run(function () use ($orderId, $address, $actorId): Order {
            $order = $this->lock->acquire($orderId);

            if (! $order->status->allowsShippingChanges()) {
                throw ShippingAddressLocked::for($order->status);
            }

            // Compared as values: jsonb does not keep key order.
            if ($order->shippingAddress() == $address) {
                return $order;
            }

            $order->shipping_address = $address->toArray();
            $order->version++;
            $order->save();

            $this->history->record($order, 'shipping_address_updated', $order->status, $actorId, ['shipping_address' => $address->toArray()]);

            return $order;
        });
    }
}
