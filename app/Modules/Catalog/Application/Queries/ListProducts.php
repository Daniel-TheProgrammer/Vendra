<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

use App\Modules\Catalog\Domain\ProductStatus;
use App\Modules\Catalog\Domain\Sku;
use App\Modules\Catalog\Infrastructure\Models\Product;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;

/**
 * Newest first, keyset-paginated on the time-ordered UUID v7 key: page cost
 * stays constant however deep a client scrolls, and rows inserted while it
 * pages never shift or duplicate entries the way offsets do. The cursor is
 * Laravel's opaque base64 encoding of the last key seen.
 *
 * Incremental sync: pass the time the previous sync *started* as
 * updatedSince. Every product changed since then (including its variants and
 * prices, which bump the product) is returned. Stock levels are not part of
 * the product's version; read them from the stock endpoints.
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
        ?DateTimeInterface $updatedSince = null,
        bool $withVariants = true,
        int $perPage = 25,
        ?string $cursor = null,
    ): CursorPaginator {
        return Product::query()
            ->when($withVariants, static fn (Builder $query) => $query->with('variants.prices', 'variants.inventory'))
            ->when($status, static fn (Builder $query, ProductStatus $status) => $query->where('status', $status->value))
            ->when($search, static fn (Builder $query, string $search) => $query->where('name', 'ilike', '%'.self::escapeLike($search).'%'))
            ->when($sku, static fn (Builder $query, string $sku) => $query->whereHas('variants', static fn (Builder $variants) => $variants->where('sku', Sku::normalise($sku))))
            // An explicit timestamptz literal: the query grammar's default date
            // format would drop both the offset and the microseconds.
            ->when($updatedSince, static fn (Builder $query, DateTimeInterface $since) => $query->where('updated_at', '>=', $since->format('Y-m-d H:i:s.uP')))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
