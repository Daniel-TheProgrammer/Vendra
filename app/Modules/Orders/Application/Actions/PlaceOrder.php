<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Actions;

use App\Modules\Catalog\Application\Actions\ReserveStock;
use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Application\Queries\FindSellableVariants;
use App\Modules\Catalog\Application\Queries\FindVariantPrice;
use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Domain\Price;
use App\Modules\Orders\Application\Commands\OrderLineInput;
use App\Modules\Orders\Application\Commands\PlaceOrderCommand;
use App\Modules\Orders\Application\Pricing\PricedLine;
use App\Modules\Orders\Application\Pricing\PricingService;
use App\Modules\Orders\Application\Services\CustomerDirectory;
use App\Modules\Orders\Application\Services\OrderHistory;
use App\Modules\Orders\Application\Services\OrderNumbers;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Orders\Domain\OrderLineRejected;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderLine;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

/**
 * Places an order in one transaction:
 *
 *   1. every line is checked against the catalog and priced at this moment;
 *   2. totals come from the PricingService (lines + tax strategy);
 *   3. the stock for every line is reserved: all of it, or the whole order
 *      is refused with InsufficientStock and nothing at all is written;
 *   4. the order gets its number and is stored with a snapshot of its lines
 *      and the first entry of its history.
 *
 * Steps run in that order so the order-number counter, which serialises
 * placements per tenant, is locked only for the final inserts.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private FindSellableVariants $variants,
        private FindVariantPrice $prices,
        private PricingService $pricing,
        private ReserveStock $reserve,
        private CustomerDirectory $customers,
        private OrderNumbers $numbers,
        private OrderHistory $history,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @throws OrderLineRejected
     * @throws InsufficientStock
     */
    public function __invoke(PlaceOrderCommand $command): Order
    {
        $order = $this->transaction->run(function () use ($command): Order {
            $now = $this->clock->now();
            $lines = $this->price($command, $now);
            $totals = $this->pricing->totals($command->currency, $lines, $command->shippingAddress);

            $customer = $command->customerId !== null
                ? $this->customers->find($command->customerId)
                : $this->customers->findOrCreate((string) $command->customerEmail, $command->customerName);

            $orderId = (string) Str::uuid7();

            ($this->reserve)(
                array_map(static fn (PricedLine $line): StockLine => new StockLine($line->variantId, $line->quantity), $lines),
                Order::stockReference($orderId),
            );

            $order = new Order([
                'number' => $this->numbers->next(),
                'customer_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'currency' => $command->currency,
                'subtotal' => $totals->subtotal->amount,
                'tax' => $totals->tax->amount,
                'total' => $totals->total->amount,
                'shipping_address' => $command->shippingAddress?->toArray(),
                'placed_by' => $command->actorId,
                'placed_at' => $now,
            ]);
            $order->id = $orderId;
            $order->save();

            foreach ($lines as $position => $line) {
                OrderLine::query()->create([
                    'order_id' => $order->id,
                    'position' => $position + 1,
                    'variant_id' => $line->variantId,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'unit_price' => $line->unitPrice->amount,
                    'quantity' => $line->quantity,
                    'line_total' => $line->lineTotal->amount,
                ]);
            }

            $this->history->record($order, 'placed', null, $command->actorId, ['number' => $order->number, 'total' => $order->total]);

            return $order;
        });

        $this->events->dispatch(new OrderPlaced($order->tenant_id, $order->id, $order->version, $this->clock->now()));

        return $order;
    }

    /**
     * @return non-empty-list<PricedLine>
     *
     * @throws OrderLineRejected
     */
    private function price(PlaceOrderCommand $command, DateTimeInterface $at): array
    {
        $variants = ($this->variants)(array_map(static fn (OrderLineInput $line): string => $line->variantId, $command->lines));
        $priced = [];

        foreach ($command->lines as $index => $input) {
            $number = $index + 1;
            $variant = $variants[$input->variantId] ?? throw OrderLineRejected::unknownVariant($number, $input->variantId);

            if (! $variant->isSellable) {
                throw OrderLineRejected::notForSale($number, $input->variantId);
            }

            $price = ($this->prices)($input->variantId, $command->currency, $at);

            if (! $price instanceof Price) {
                throw OrderLineRejected::notPricedIn($number, $input->variantId, $command->currency->value);
            }

            $priced[] = new PricedLine($variant->variantId, $variant->sku, $variant->name, $price->amount, $input->quantity);
        }

        return $priced;
    }
}
