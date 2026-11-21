<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Database\Seeders\TenantSeeder;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Local development data. Idempotent.
 *
 * Sign in as owner@vendra.local / correct-horse-battery-1: owner of "acme",
 * staff in "globex", so the tenant-selection and switch flows can be tried.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantSeeder::class);

        $user = User::query()->firstOrCreate(
            ['email' => 'owner@vendra.local'],
            ['name' => 'Demo Owner', 'password' => 'correct-horse-battery-1'],
        );
        $user->markEmailAsVerified();

        $roles = ['acme' => MembershipRole::Owner, 'globex' => MembershipRole::Staff];

        foreach ($roles as $slug => $role) {
            $tenant = Tenant::query()->where('slug', $slug)->firstOrFail();

            Membership::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $role],
            );
        }
    }
}
