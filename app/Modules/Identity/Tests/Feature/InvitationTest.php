<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Invitation;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Notifications\MemberInvited;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    }

    private function invite(string $email, string $role = 'staff'): string
    {
        $this->postJson('/api/v1/members/invitations', ['email' => $email, 'role' => $role])->assertCreated();

        $token = null;
        Notification::assertSentTo(
            new AnonymousNotifiable,
            MemberInvited::class,
            function (MemberInvited $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
                if (($notifiable->routes['mail'] ?? null) !== mb_strtolower($email)) {
                    return false;
                }

                $token = $notification->token;

                return true;
            },
        );

        self::assertIsString($token);

        return $token;
    }

    #[Test]
    public function a_new_person_accepts_and_gets_a_verified_account_membership_and_token(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Admin);
        $token = $this->invite('New.Hire@example.com');

        self::assertSame(hash('sha256', $token), Invitation::query()->value('token_hash'), 'only a hash of the token may be stored');

        $this->forgetAuthenticatedUser();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => 'New Hire',
            'password' => 'fresh-start-2026',
            'password_confirmation' => 'fresh-start-2026',
        ])
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.user.email', 'new.hire@example.com')
            ->assertJsonPath('data.user.email_verified', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.joined', 'tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function an_existing_account_joins_without_a_password_and_the_token_is_single_use(): void
    {
        $existing = User::factory()->create(['email' => 'veteran@example.com']);
        $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $token = $this->invite('veteran@example.com', 'admin');

        $this->forgetAuthenticatedUser();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        self::assertSame(MembershipRole::Admin, Membership::query()->where('user_id', $existing->id)->firstOrFail()->role);

        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:invalid-invitation');
    }

    #[Test]
    public function a_new_account_needs_a_name_and_password(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $token = $this->invite('someone@example.com');

        $this->forgetAuthenticatedUser();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name', 'password']]);

        self::assertNull(Invitation::query()->value('accepted_at'));
    }

    #[Test]
    public function only_owners_can_invite_owners_and_members_cannot_be_invited_twice(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Admin);

        $this->postJson('/api/v1/members/invitations', ['email' => 'boss@example.com', 'role' => 'owner'])->assertForbidden();

        $member = User::factory()->create(['email' => 'already@example.com']);
        Membership::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id]);

        $this->postJson('/api/v1/members/invitations', ['email' => 'already@example.com', 'role' => 'staff'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:already-a-member');
    }

    #[Test]
    public function reinviting_replaces_the_open_invitation_and_revoked_or_expired_ones_fail(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $first = $this->invite('twice@example.com');
        Notification::fake();
        $second = $this->invite('twice@example.com');

        $this->getJson('/api/v1/members/invitations')->assertOk()->assertJsonCount(1, 'data');

        $this->forgetAuthenticatedUser();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $first, 'name' => 'X', 'password' => 'long-enough-pass-1', 'password_confirmation' => 'long-enough-pass-1'])
            ->assertUnprocessable();

        $this->travel(8)->days();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $second, 'name' => 'X', 'password' => 'long-enough-pass-1', 'password_confirmation' => 'long-enough-pass-1'])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:invalid-invitation');
    }

    #[Test]
    public function an_open_invitation_can_be_revoked(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Owner);
        $token = $this->invite('maybe@example.com');
        $id = (string) Invitation::query()->value('id');

        $this->deleteJson('/api/v1/members/invitations/'.$id)->assertNoContent();
        $this->deleteJson('/api/v1/members/invitations/'.$id)->assertNotFound();

        $this->forgetAuthenticatedUser();
        $this->withoutToken()->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'M', 'password' => UserFactory::PASSWORD, 'password_confirmation' => UserFactory::PASSWORD])
            ->assertUnprocessable();
    }
}
