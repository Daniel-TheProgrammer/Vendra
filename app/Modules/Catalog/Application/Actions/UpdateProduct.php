<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\UpdateProductCommand;
use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class UpdateProduct
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(UpdateProductCommand $command): Product
    {
        /** @var array{Product, bool} $result */
        $result = $this->transaction->run(function () use ($command): array {
            $product = $this->lock->acquireEditable($command->productId, $command->expectedVersion);

            if ($command->name !== null) {
                $product->name = $command->name;
            }

            if ($command->status instanceof ProductStatus) {
                $product->status = $command->status;
            }

            if ($command->updatesDescription) {
                $product->description = $command->description;
            }

            // A request that changes nothing must not invalidate the ETag other clients hold.
            if (! $product->isDirty()) {
                return [$product, false];
            }

            $product->touchVersion();
            $product->save();

            return [$product, true];
        });

        [$product, $changed] = $result;

        if ($changed) {
            $this->events->dispatch(new ProductUpdated($product->tenant_id, $product->id, $product->version, $this->clock->now()));
        }

        return $product;
    }
}
