<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Queries;

use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use Illuminate\Pagination\CursorPaginator;

final readonly class ListSubscriptions
{
    public const MAX_PER_PAGE = 100;

    /**
     * @return CursorPaginator<int, WebhookSubscription>
     */
    public function __invoke(?SubscriptionStatus $status = null, int $perPage = 25, ?string $cursor = null): CursorPaginator
    {
        return WebhookSubscription::query()
            ->when($status instanceof SubscriptionStatus, static fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }
}
