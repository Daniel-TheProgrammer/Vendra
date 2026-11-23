<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Currency;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Stops selling the variant in one currency.
 */
final readonly class RemoveVariantPrice
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(string $variantId, Currency $currency, ?int $expectedVersion = null): Product
    {
        $product = $this->transaction->run(function () use ($variantId, $currency, $expectedVersion): Product {
            $variant = ProductVariant::query()->whereKey($variantId)->first()
                ?? throw EntityNotFound::withId('Variant', $variantId);

            $product = $this->lock->acquireEditable($variant->product_id, $expectedVersion);

            $deleted = VariantPrice::query()
                ->where('variant_id', $variant->id)
                ->where('currency', $currency->value)
                ->delete();

            if ($deleted === 0) {
                throw EntityNotFound::withId('Price', $variant->id.'/'.$currency->value);
            }

            $product->touchVersion();
            $product->save();

            return $product;
        });

        $this->events->dispatch(new ProductUpdated($product->tenant_id, $product->id, $product->version, $this->clock->now()));

        return $product;
    }
}
