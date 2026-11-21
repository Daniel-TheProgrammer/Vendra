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
final class SessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function me_describes_the_user_the_current_tenant_and_all_memberships(): void
    {
        $acme = Tenant::factory()->create(['slug' => 'acme']);
        $globex = Tenant::factory()->create(['slug' => 'globex']);
        $user = $this->actingAsMember($acme, MembershipRole::Admin);
        Membership::factory()->create(['tenant_id' => $globex->id, 'user_id' => $user->id, 'role' => MembershipRole::ReadOnly]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonCount(2, 'data.memberships');
    }

    #[Test]
    public function requests_without_a_token_are_unauthenticated(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'urn:problem-type:unauthenticated');
    }

    #[Test]
    public function logout_revokes_the_token_it_was_called_with(): void
    {
        $user = $this->actingAsMember();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_id' => $user->id]);

        $this->forgetAuthenticatedUser();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function expired_tokens_stop_working(): void
    {
        $this->actingAsMember();
        $this->getJson('/api/v1/me')->assertOk();

        $this->travel(31)->days();
        $this->forgetAuthenticatedUser();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
