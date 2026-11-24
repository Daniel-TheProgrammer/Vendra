<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderLine;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Amounts come as a decimal string for display and integer minor units for
 * arithmetic, like catalog prices.
 */
final class OrderResource extends ApiResource
{
    public function __construct(Order $order)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'currency' => $order->currency->value,
            'customer' => [
                'id' => $order->customer->id,
                'email' => $order->customer->email,
                'name' => $order->customer->name,
            ],
            'lines' => $order->lines->map(static fn (OrderLine $line): array => [
                'id' => $line->id,
                'variant_id' => $line->variant_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'quantity' => $line->quantity,
                ...self::money('unit_price', $line->unitPrice($order->currency)),
                ...self::money('line_total', $line->lineTotal($order->currency)),
            ])->values()->all(),
            ...self::money('subtotal', $order->money($order->subtotal)),
            ...self::money('tax', $order->money($order->tax)),
            ...self::money('total', $order->money($order->total)),
            'shipping_address' => $order->shipping_address,
            'version' => $order->version,
            'placed_at' => $order->placed_at->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'fulfilled_at' => $order->fulfilled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    private static function money(string $field, Money $amount): array
    {
        return [$field => $amount->toDecimal(), $field.'_minor' => $amount->amount];
    }
}
