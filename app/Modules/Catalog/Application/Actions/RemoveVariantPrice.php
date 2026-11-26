<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Currency;

/**
 * Removes the variant's base price in one currency. Scheduled prices in that
 * currency stay, so the variant remains sellable inside their windows.
 */
final readonly class RemoveVariantPrice
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    public function __invoke(string $variantId, Currency $currency, ?int $expectedVersion = null): Product
    {
        return $this->transaction->run(function () use ($variantId, $currency, $expectedVersion): Product {
            $variant = ProductVariant::query()->whereKey($variantId)->first()
                ?? throw EntityNotFound::withId('Variant', $variantId);

            $product = $this->lock->acquireEditable($variant->product_id, $expectedVersion);

            $deleted = VariantPrice::query()
                ->where('variant_id', $variant->id)
                ->where('currency', $currency->value)
                ->whereNull('valid_from')
                ->whereNull('valid_to')
                ->delete();

            if ($deleted === 0) {
                throw EntityNotFound::withId('Price', $variant->id.'/'.$currency->value);
            }

            $product->touchVersion();
            $product->save();
            $this->events->record(new PriceChanged($product->tenant_id, $product->id, $product->version, $this->clock->now(), $variantId, $currency));

            return $product;
        });
    }
}
