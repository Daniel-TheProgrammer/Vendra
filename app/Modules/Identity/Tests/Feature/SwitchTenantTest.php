<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class SwitchTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function switching_issues_a_token_for_the_other_tenant_and_kills_the_old_one(): void
    {
        $acme = Tenant::factory()->create(['slug' => 'acme']);
        $globex = Tenant::factory()->create(['slug' => 'globex']);
        $user = $this->actingAsMember($acme);
        Membership::factory()->create(['tenant_id' => $globex->id, 'user_id' => $user->id, 'role' => MembershipRole::Staff]);

        $newToken = $this->postJson('/api/v1/auth/switch-tenant', ['tenant' => 'globex'])
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'globex')
            ->assertJsonPath('data.role', 'staff')
            ->json('data.token');

        $this->forgetAuthenticatedUser();
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withToken((string) $newToken)->getJson('/api/v1/tenant')
            ->assertOk()
            ->assertJsonPath('data.slug', 'globex');
    }

    #[Test]
    public function switching_to_a_tenant_without_membership_is_denied(): void
    {
        $this->actingAsMember();
        Tenant::factory()->create(['slug' => 'initech']);

        $this->postJson('/api/v1/auth/switch-tenant', ['tenant' => 'initech'])
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:tenant-access-denied');
    }
}
