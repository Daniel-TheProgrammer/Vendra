<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Database\Factories;

use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'plan' => TenantPlan::Free,
            'status' => TenantStatus::Active,
            'settings' => [],
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }

    public function onPlan(TenantPlan $plan): static
    {
        return $this->state(['plan' => $plan]);
    }
}
