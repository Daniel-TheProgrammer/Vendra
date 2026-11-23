<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Withdraws a scheduled price, including one that is running: the base price
 * (if any) applies again immediately. Base prices are removed through
 * RemoveVariantPrice instead.
 */
final readonly class CancelScheduledPrice
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(string $variantId, string $priceId, ?int $expectedVersion = null): Product
    {
        /** @var array{Product, VariantPrice} $result */
        $result = $this->transaction->run(function () use ($variantId, $priceId, $expectedVersion): array {
            $variant = ProductVariant::query()->whereKey($variantId)->first()
                ?? throw EntityNotFound::withId('Variant', $variantId);

            $product = $this->lock->acquireEditable($variant->product_id, $expectedVersion);

            $price = VariantPrice::query()
                ->where('variant_id', $variant->id)
                ->whereKey($priceId)
                ->where(static fn ($query) => $query->whereNotNull('valid_from')->orWhereNotNull('valid_to'))
                ->first()
                ?? throw EntityNotFound::withId('Scheduled price', $priceId);

            $price->delete();
            $product->touchVersion();
            $product->save();

            return [$product, $price];
        });

        [$product, $price] = $result;
        $this->events->dispatch(new PriceChanged($product->tenant_id, $product->id, $product->version, $this->clock->now(), $variantId, $price->currency));

        return $product;
    }
}
