<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;

final class ShowCurrentTenantController
{
    public function __invoke(TenantContext $context): TenantResource
    {
        return new TenantResource(Tenant::query()->findOrFail($context->id()));
    }
}
