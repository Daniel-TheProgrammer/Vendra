<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Application\Services\StockLedger;
use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Domain\StockMovementType;
use App\Modules\Catalog\Infrastructure\Models\InventoryLevel;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Holds stock for an order. All lines or none: if any variant is short, the
 * whole reservation is refused with InsufficientStock and nothing is held.
 *
 * Joins the caller's transaction when there is one, so an order and its
 * reservation commit or roll back together.
 */
final readonly class ReserveStock
{
    public function __construct(
        private TransactionRunner $transaction,
        private StockLedger $ledger,
    ) {}

    /**
     * @param  non-empty-list<StockLine>  $lines
     * @param  string  $reference  what the stock is held for, e.g. an order id
     * @return array<string, InventoryLevel> keyed by variant id
     *
     * @throws InsufficientStock
     */
    public function __invoke(array $lines, string $reference): array
    {
        return $this->transaction->run(function () use ($lines, $reference): array {
            $totals = StockLine::totals($lines);
            $levels = $this->ledger->lock(array_keys($totals));

            foreach ($levels as $variantId => $level) {
                $this->ledger->record(
                    level: $level,
                    next: $level->toStockLevel()->reserve($totals[$variantId]),
                    type: StockMovementType::Reservation,
                    quantity: $totals[$variantId],
                    reference: $reference,
                );
            }

            return $levels;
        });
    }
}
