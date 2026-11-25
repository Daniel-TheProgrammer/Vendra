<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Queries;

use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Models\Refund;
use Illuminate\Pagination\CursorPaginator;

/**
 * Newest first, cursor-paginated on the time-ordered id.
 */
final readonly class ListRefunds
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, Refund>
     */
    public function __invoke(?string $paymentIntentId = null, ?RefundStatus $status = null, int $perPage = 25, ?string $cursor = null): CursorPaginator
    {
        return Refund::query()
            ->when($paymentIntentId !== null, static fn ($query) => $query->where('payment_intent_id', $paymentIntentId))
            ->when($status instanceof RefundStatus, static fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }
}
