<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Domain\StockLevel;
use App\Modules\Catalog\Domain\StockMovementType;
use App\Modules\Catalog\Infrastructure\Models\InventoryLevel;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\StockMovement;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * The only writer of inventory levels. Call inside a transaction.
 *
 * lock() takes FOR UPDATE row locks on the levels of the given variants, in
 * ascending variant id order. Every writer locks in that same order, so two
 * transactions reserving overlapping sets of variants queue instead of
 * deadlocking. The level a writer then reads cannot change until it commits,
 * so check-then-write is safe: availability can never be oversold.
 */
final readonly class StockLedger
{
    public function __construct(
        private TenantContext $context,
        private Clock $clock,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @return array<string, InventoryLevel> keyed by variant id, in lock order
     *
     * @throws EntityNotFound when a variant does not exist in this tenant
     */
    public function lock(array $variantIds): array
    {
        $ids = array_values(array_unique($variantIds));
        sort($ids);

        $known = ProductVariant::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach ($ids as $id) {
            if (! in_array($id, $known, true)) {
                throw EntityNotFound::withId('Variant', $id);
            }
        }

        $this->createMissingLevels($ids);

        /** @var array<string, InventoryLevel> $levels */
        $levels = InventoryLevel::query()
            ->whereIn('variant_id', $ids)
            ->orderBy('variant_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('variant_id')
            ->all();

        return $levels;
    }

    public function record(
        InventoryLevel $level,
        StockLevel $next,
        StockMovementType $type,
        int $quantity,
        ?string $reason = null,
        ?string $reference = null,
        ?string $note = null,
        ?string $actorId = null,
    ): StockMovement {
        $level->on_hand = $next->onHand;
        $level->reserved = $next->reserved;
        $level->version++;
        $level->save();

        return StockMovement::query()->create([
            'variant_id' => $level->variant_id,
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'reference' => $reference,
            'note' => $note,
            'on_hand_after' => $next->onHand,
            'reserved_after' => $next->reserved,
            'actor_id' => $actorId,
        ]);
    }

    /**
     * ON CONFLICT DO NOTHING: two first-time writers racing to create the
     * same row both succeed, and both then lock the one row that exists.
     *
     * @param  list<string>  $variantIds
     */
    private function createMissingLevels(array $variantIds): void
    {
        $now = $this->clock->now();
        $tenantId = $this->context->id();

        InventoryLevel::query()->insertOrIgnore(array_map(static fn (string $variantId): array => [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'variant_id' => $variantId,
            'on_hand' => 0,
            'reserved' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $variantIds));
    }
}
