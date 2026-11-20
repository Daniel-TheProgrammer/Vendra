<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Marks a model as tenant-owned: reads are scoped to the current tenant and
 * tenant_id is filled from the context on create.
 *
 * The table must carry a tenant_id column (see the tenantId() Blueprint macro).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    /**
     * Escape hatch for cross-tenant work: Model::withoutTenancy()->...
     * Deliberately unavailable while serving HTTP requests, where the tenant
     * must always be known.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithoutTenancy(Builder $query): void
    {
        if (! app()->runningInConsole() && ! app()->runningUnitTests()) {
            throw new LogicException('withoutTenancy() may only be used from console commands and tests.');
        }

        $query->withoutGlobalScope(TenantScope::class);
    }
}
