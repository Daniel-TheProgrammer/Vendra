<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queries;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;

final class FindTenants
{
    /**
     * @param  array<array-key, string>  $ids  duplicates are ignored
     * @return array<string, TenantSnapshot> keyed by tenant id; unknown ids are absent
     */
    public function __invoke(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Tenant::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->mapWithKeys(static fn (Tenant $tenant): array => [$tenant->id => TenantSnapshot::fromModel($tenant)])
            ->all();
    }
}
