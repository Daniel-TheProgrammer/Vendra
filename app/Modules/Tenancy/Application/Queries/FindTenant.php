<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queries;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Support\Str;

final class FindTenant
{
    /**
     * Looks a tenant up by id or by slug.
     */
    public function __invoke(string $idOrSlug): ?TenantSnapshot
    {
        $column = Str::isUuid($idOrSlug) ? 'id' : 'slug';

        $tenant = Tenant::query()->where($column, $idOrSlug)->first();

        return $tenant === null ? null : TenantSnapshot::fromModel($tenant);
    }
}
