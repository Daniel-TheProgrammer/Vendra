<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Database\Seeders;

use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Two demo tenants for local development. Idempotent.
 */
final class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            ['slug' => 'acme', 'name' => 'Acme Corporation', 'plan' => TenantPlan::Pro],
            ['slug' => 'globex', 'name' => 'Globex', 'plan' => TenantPlan::Free],
        ];

        foreach ($tenants as $tenant) {
            Tenant::query()->firstOrCreate(
                ['slug' => $tenant['slug']],
                [
                    'name' => $tenant['name'],
                    'plan' => $tenant['plan'],
                    'status' => TenantStatus::Active,
                    'settings' => [],
                ],
            );
        }
    }
}
