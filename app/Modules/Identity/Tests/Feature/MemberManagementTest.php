<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function member(MembershipRole $role, ?User $user = null): Membership
    {
        return Membership::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => ($user ?? User::factory()->create())->id,
            'role' => $role,
        ]);
    }

    #[Test]
    public function every_member_can_list_the_team_and_the_roles(): void
    {
        $this->member(MembershipRole::Admin);
        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);

        $this->getJson('/api/v1/members')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.role', 'owner');
    }

    #[Test]
    public function staff_and_read_only_cannot_manage_members(): void
    {
        $target = $this->member(MembershipRole::Staff);

        foreach ([MembershipRole::Staff, MembershipRole::ReadOnly] as $role) {
            $this->actingAsMember($this->tenant, $role, User::factory()->create());

            $this->patchJson('/api/v1/members/'.$target->id.'/role', ['role' => 'admin'])
                ->assertForbidden()
                ->assertJsonPath('type', 'urn:problem-type:action-not-permitted');
            $this->deleteJson('/api/v1/members/'.$target->id)->assertForbidden();
        }
    }

    #[Test]
    public function an_admin_manages_people_below_owner_but_never_owners(): void
    {
        $owner = $this->member(MembershipRole::Owner);
        $staff = $this->member(MembershipRole::Staff);
        $this->actingAsMember($this->tenant, MembershipRole::Admin);

        $this->patchJson('/api/v1/members/'.$staff->id.'/role', ['role' => 'read_only'])
            ->assertOk()
            ->assertJsonPath('data.role', 'read_only');

        $this->patchJson('/api/v1/members/'.$staff->id.'/role', ['role' => 'owner'])->assertForbidden();
        $this->patchJson('/api/v1/members/'.$owner->id.'/role', ['role' => 'staff'])->assertForbidden();
        $this->deleteJson('/api/v1/members/'.$owner->id)->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.role_changed', 'tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function the_last_owner_can_be_neither_demoted_nor_removed(): void
    {
        $me = $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $mine = Membership::query()->where('user_id', $me->id)->firstOrFail();

        $this->patchJson('/api/v1/members/'.$mine->id.'/role', ['role' => 'admin'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:last-owner-required');
        $this->deleteJson('/api/v1/members/'.$mine->id)->assertConflict();

        $this->member(MembershipRole::Owner);

        $this->patchJson('/api/v1/members/'.$mine->id.'/role', ['role' => 'admin'])->assertOk();
    }

    #[Test]
    public function removing_a_member_cuts_off_their_tokens_for_this_tenant_only(): void
    {
        $elsewhere = Tenant::factory()->create();
        $victim = User::factory()->create();
        $membership = $this->member(MembershipRole::Staff, $victim);

        $this->actingAsMember($elsewhere, MembershipRole::Staff, $victim);
        $victimToken = $this->actingAsMemberTokenFor($victim, $this->tenant);

        $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $this->deleteJson('/api/v1/members/'.$membership->id)->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $victim->id, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $victim->id, 'tenant_id' => $elsewhere->id]);

        $this->forgetAuthenticatedUser();
        $this->withToken($victimToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    private function actingAsMemberTokenFor(User $user, Tenant $tenant): string
    {
        return $this->app->make(TokenIssuer::class)
            ->issue($user, $tenant->id, 'victim')
            ->plainTextToken;
    }
}
