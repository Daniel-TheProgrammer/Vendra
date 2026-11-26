<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Domain\ProductVersionMismatch;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Products are archived, never deleted: past orders keep pointing at their
 * variants. Archiving an archived product is a no-op, so clients can retry
 * safely, even with the version they sent the first time.
 */
final readonly class ArchiveProduct
{
    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    public function __invoke(string $productId, ?int $expectedVersion = null): Product
    {
        /** @var array{Product, bool} $result */
        $result = $this->transaction->run(function () use ($productId, $expectedVersion): array {
            $product = $this->lock->acquire($productId);

            if ($product->isArchived()) {
                return [$product, false];
            }

            if ($expectedVersion !== null && $expectedVersion !== $product->version) {
                throw ProductVersionMismatch::expected($expectedVersion, $product->version);
            }

            $product->status = ProductStatus::Archived;
            $product->archived_at = $this->clock->now();
            $product->touchVersion();
            $product->save();
            $this->events->record(new ProductArchived($product->tenant_id, $product->id, $product->version, $this->clock->now()));

            return [$product, true];
        });

        return $result[0];
    }
}
