<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Queries;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\CursorPaginator;

/**
 * Newest first, keyset-paginated on the UUID v7 key (docs/module-template.md).
 * The placed-at range is half-open: [placedFrom, placedTo).
 */
final readonly class ListOrders
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, Order>
     */
    public function __invoke(
        ?OrderStatus $status = null,
        ?string $customerId = null,
        ?DateTimeInterface $placedFrom = null,
        ?DateTimeInterface $placedTo = null,
        int $perPage = 25,
        ?string $cursor = null,
    ): CursorPaginator {
        return Order::query()
            ->with('customer', 'lines')
            ->when($status, static fn (Builder $query, OrderStatus $status) => $query->where('status', $status->value))
            ->when($customerId, static fn (Builder $query, string $customerId) => $query->where('customer_id', $customerId))
            ->when($placedFrom, static fn (Builder $query, DateTimeInterface $from) => $query->where('placed_at', '>=', $from->format('Y-m-d H:i:s.uP')))
            ->when($placedTo, static fn (Builder $query, DateTimeInterface $to) => $query->where('placed_at', '<', $to->format('Y-m-d H:i:s.uP')))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }
}
