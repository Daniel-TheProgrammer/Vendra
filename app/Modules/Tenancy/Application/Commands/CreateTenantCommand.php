<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Commands;

use App\Modules\Tenancy\Domain\TenantPlan;

final readonly class CreateTenantCommand
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public TenantPlan $plan = TenantPlan::Free,
    ) {}
}
