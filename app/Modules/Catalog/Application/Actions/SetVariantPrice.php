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
use App\Shared\Domain\Money\Money;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Creates or replaces the variant's base price (the one without a validity
 * window) in the money's currency. Scheduled prices are left alone. Setting
 * the price it already has changes nothing. Returns the product, whose
 * version moved if the price did.
 */
final readonly class SetVariantPrice
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(string $variantId, Money $price, ?int $expectedVersion = null): Product
    {
        /** @var array{Product, bool} $result */
        $result = $this->transaction->run(function () use ($variantId, $price, $expectedVersion): array {
            $variant = ProductVariant::query()->whereKey($variantId)->first()
                ?? throw EntityNotFound::withId('Variant', $variantId);

            $product = $this->lock->acquireEditable($variant->product_id, $expectedVersion);

            $row = VariantPrice::query()->firstOrNew([
                'variant_id' => $variant->id,
                'currency' => $price->currency->value,
                'valid_from' => null,
                'valid_to' => null,
            ]);
            $row->amount = $price->amount;

            if (! $row->isDirty()) {
                return [$product, false];
            }

            $row->save();
            $product->touchVersion();
            $product->save();

            return [$product, true];
        });

        [$product, $changed] = $result;

        if ($changed) {
            $this->events->dispatch(new PriceChanged($product->tenant_id, $product->id, $product->version, $this->clock->now(), $variantId, $price->currency));
        }

        return $product;
    }
}
