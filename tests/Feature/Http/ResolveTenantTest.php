<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tenant a request runs as comes from the token, never from the client.
 */
#[Group('database')]
final class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_tenant_is_the_one_the_token_is_bound_to(): void
    {
        $acme = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Corporation']);
        $globex = Tenant::factory()->create(['slug' => 'globex']);
        $user = $this->actingAsMember($acme);
        Membership::factory()->create(['tenant_id' => $globex->id, 'user_id' => $user->id, 'role' => MembershipRole::Owner]);

        $this->getJson('/api/v1/tenant', ['X-Tenant-Id' => 'globex'])
            ->assertOk()
            ->assertJsonPath('data.id', $acme->id)
            ->assertJsonPath('data.name', 'Acme Corporation');
    }

    #[Test]
    public function removing_the_membership_cuts_off_existing_tokens(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->actingAsMember($tenant);
        $this->getJson('/api/v1/tenant')->assertOk();

        Membership::query()->where('user_id', $user->id)->delete();
        $this->forgetAuthenticatedUser();

        $this->getJson('/api/v1/tenant')->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_tenant_is_refused(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $this->actingAsMember($tenant);

        $this->getJson('/api/v1/tenant')
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:tenant-suspended');
    }

    #[Test]
    public function the_context_is_cleared_after_the_request(): void
    {
        $this->actingAsMember();

        $this->getJson('/api/v1/tenant')->assertOk();

        self::assertFalse($this->app->make(TenantContext::class)->has());
    }
}
