<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Application\Services\StockLedger;
use App\Modules\Catalog\Domain\StockMovementType;
use App\Modules\Catalog\Domain\StockNotReserved;
use App\Modules\Catalog\Infrastructure\Models\InventoryLevel;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Returns held stock to the available pool, e.g. when an order is cancelled
 * or expires unpaid. All lines or none.
 */
final readonly class ReleaseStock
{
    public function __construct(
        private TransactionRunner $transaction,
        private StockLedger $ledger,
    ) {}

    /**
     * @param  non-empty-list<StockLine>  $lines
     * @return array<string, InventoryLevel> keyed by variant id
     *
     * @throws StockNotReserved
     */
    public function __invoke(array $lines, string $reference): array
    {
        return $this->transaction->run(function () use ($lines, $reference): array {
            $totals = StockLine::totals($lines);
            $levels = $this->ledger->lock(array_keys($totals));

            foreach ($levels as $variantId => $level) {
                $this->ledger->record(
                    level: $level,
                    next: $level->toStockLevel()->release($totals[$variantId]),
                    type: StockMovementType::Release,
                    quantity: $totals[$variantId],
                    reference: $reference,
                );
            }

            return $levels;
        });
    }
}
