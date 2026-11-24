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
 * Turns a reservation into a shipment when an order is fulfilled: the units
 * leave on-hand stock and the hold on them ends. All lines or none.
 */
final readonly class ShipStock
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
                    next: $level->toStockLevel()->ship($totals[$variantId]),
                    type: StockMovementType::Shipment,
                    quantity: $totals[$variantId],
                    reference: $reference,
                );
            }

            return $levels;
        });
    }
}
