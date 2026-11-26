<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Services\StockLedger;
use App\Modules\Catalog\Domain\Events\StockAdjusted;
use App\Modules\Catalog\Domain\StockMovementType;
use App\Modules\Catalog\Infrastructure\Models\InventoryLevel;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Changes on-hand stock: goods received, returns restocked, write-offs and
 * recounts. Reserved units are protected: the adjustment cannot take on-hand
 * below what orders already hold.
 */
final readonly class AdjustStock
{
    public function __construct(
        private TransactionRunner $transaction,
        private StockLedger $ledger,
        private Clock $clock,
        private EventRecorder $events,
    ) {}

    public function __invoke(AdjustStockCommand $command): InventoryLevel
    {
        return $this->transaction->run(function () use ($command): InventoryLevel {
            $level = $this->ledger->lock([$command->variantId])[$command->variantId];
            $next = $level->toStockLevel()->adjust($command->delta);

            $this->ledger->record(
                level: $level,
                next: $next,
                type: StockMovementType::Adjustment,
                quantity: $command->delta,
                reason: $command->reason->value,
                note: $command->note,
                actorId: $command->actorId,
            );

            $this->events->record(new StockAdjusted(
                tenantId: $level->tenant_id,
                variantId: $level->variant_id,
                delta: $command->delta,
                onHand: $level->on_hand,
                available: $level->on_hand - $level->reserved,
                reason: $command->reason->value,
                occurredAt: $this->clock->now(),
            ));

            return $level;
        });
    }
}
