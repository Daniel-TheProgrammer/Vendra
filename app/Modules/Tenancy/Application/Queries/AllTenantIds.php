<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queries;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Generator;

/**
 * Every tenant id, streamed in key order, for platform jobs that run once
 * per tenant (ledger reconciliation, reporting rollups).
 */
final readonly class AllTenantIds
{
    /**
     * @return Generator<int, string>
     */
    public function __invoke(): Generator
    {
        foreach (Tenant::query()->orderBy('id')->lazyById(500, 'id') as $tenant) {
            yield $tenant->id;
        }
    }
}
