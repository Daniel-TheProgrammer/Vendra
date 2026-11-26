<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\CreateProductCommand;
use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Application\Services\VariantWriter;
use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * A product is created together with its variants: there is never a product
 * without at least one sellable unit.
 */
final readonly class CreateProduct
{
    public function __construct(
        private TransactionRunner $transaction,
        private VariantWriter $variants,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    public function __invoke(CreateProductCommand $command): Product
    {
        return $this->transaction->run(function () use ($command): Product {
            $this->variants->ensureSkusAreFree(array_map(static fn (VariantData $variant): string => $variant->sku, $command->variants));

            $product = new Product([
                'name' => $command->name,
                'description' => $command->description,
                'status' => $command->status,
            ]);
            $product->tenant_id = $command->tenantId;
            $product->save();

            foreach ($command->variants as $variant) {
                $this->variants->add($product, $variant);
            }

            $this->events->record(new ProductCreated($product->tenant_id, $product->id, $product->version, $this->clock->now()));

            return $product;
        });
    }
}
