<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Application\Services\VariantWriter;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class AddVariant
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private VariantWriter $variants,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(string $productId, VariantData $data, ?int $expectedVersion = null): ProductVariant
    {
        /** @var array{Product, ProductVariant} $result */
        $result = $this->transaction->run(function () use ($productId, $data, $expectedVersion): array {
            $product = $this->lock->acquireEditable($productId, $expectedVersion);

            $this->variants->ensureSkusAreFree([$data->sku]);
            $variant = $this->variants->add($product, $data);

            $product->touchVersion();
            $product->save();

            return [$product, $variant];
        });

        [$product, $variant] = $result;

        $this->events->dispatch(new ProductUpdated($product->tenant_id, $product->id, $product->version, $this->clock->now()));

        return $variant;
    }
}
