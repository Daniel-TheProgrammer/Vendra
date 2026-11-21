<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Application\Queries;

use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;

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

    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            id: $tenant->id,
            slug: $tenant->slug,
            name: $tenant->name,
            plan: $tenant->plan,
            status: $tenant->status,
        );
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    /**
     * @return array{id: string, slug: string, name: string}
     */
    public function summary(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'name' => $this->name];
    }
}
