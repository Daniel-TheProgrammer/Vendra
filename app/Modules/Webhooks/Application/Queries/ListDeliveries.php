<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Queries;

use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Pagination\CursorPaginator;

final readonly class ListDeliveries
{
    public const MAX_PER_PAGE = 100;

    /**
     * Newest first.
     *
     * @return CursorPaginator<int, WebhookDelivery>
     */
    public function __invoke(?DeliveryStatus $status = null, ?string $subscriptionId = null, ?string $eventType = null, int $perPage = 25, ?string $cursor = null): CursorPaginator
    {
        return WebhookDelivery::query()
            ->when($status instanceof DeliveryStatus, static fn ($query) => $query->where('status', $status))
            ->when($subscriptionId !== null, static fn ($query) => $query->where('subscription_id', $subscriptionId))
            ->when($eventType !== null, static fn ($query) => $query->where('event_type', $eventType))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }

    /**
     * @throws EntityNotFound
     */
    public function find(string $id): WebhookDelivery
    {
        return WebhookDelivery::query()->whereKey($id)->first() ?? throw EntityNotFound::withId('Webhook delivery', $id);
    }
}
