<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\StockMovement;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Newest first. Backed by the (variant_id, id) index.
 */
final readonly class ListStockMovements
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, StockMovement>
     */
    public function __invoke(string $variantId, int $perPage = 50, ?string $cursor = null): CursorPaginator
    {
        if (! ProductVariant::query()->whereKey($variantId)->exists()) {
            throw EntityNotFound::withId('Variant', $variantId);
        }

        return StockMovement::query()
            ->where('variant_id', $variantId)
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }
}
