<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queries;

use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantStatus;

/**
 * What other modules may know about a tenant. Never hand out the Eloquent model.
 */
final readonly class TenantSnapshot
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public TenantPlan $plan,
        public TenantStatus $status,
    ) {}

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }
}
