<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Infrastructure\Models\Product;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Newest first, keyset-paginated on the time-ordered UUID v7 key: page cost
 * stays constant however deep a client scrolls, and rows inserted while it
 * pages never shift or duplicate entries the way offsets do.
 */
final readonly class ListProducts
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, Product>
     */
    public function __invoke(
        ?ProductStatus $status = null,
        ?string $search = null,
        ?string $sku = null,
        int $perPage = 25,
        ?string $cursor = null,
    ): CursorPaginator {
        return Product::query()
            ->with('variants.prices', 'variants.inventory')
            ->when($status, static fn (Builder $query, ProductStatus $status) => $query->where('status', $status->value))
            ->when($search, static fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.self::escapeLike($search).'%'))
            ->when($sku, static fn (Builder $query, string $sku) => $query->whereHas('variants', static fn (Builder $variants) => $variants->where('sku', $sku)))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
