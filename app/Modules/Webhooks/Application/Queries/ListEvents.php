<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Queries;

use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Pagination\CursorPaginator;

/**
 * GET /events: the current tenant's event log, newest first, from the
 * outbox (published events are kept outbox.retention_days). The outbox is
 * a platform table, so the tenant filter here is what isolates tenants.
 */
final readonly class ListEvents
{
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private TenantContext $tenant,
    ) {}

    /**
     * @return CursorPaginator<int, OutboxMessage>
     */
    public function __invoke(?string $type = null, int $perPage = 25, ?string $cursor = null): CursorPaginator
    {
        return OutboxMessage::query()
            ->where('tenant_id', $this->tenant->id())
            ->when($type !== null, static fn ($query) => $query->where('event_type', $type))
            ->orderByDesc('id')
            ->cursorPaginate(min(max($perPage, 1), self::MAX_PER_PAGE), ['*'], 'cursor', $cursor);
    }
}
