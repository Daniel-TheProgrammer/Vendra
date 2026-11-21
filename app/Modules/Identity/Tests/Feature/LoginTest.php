<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'grace@example.com']);
        $this->acme = Tenant::factory()->create(['slug' => 'acme']);
        $this->member($this->acme, MembershipRole::Admin);
    }

    private function member(Tenant $tenant, MembershipRole $role): void
    {
        Membership::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $this->user->id, 'role' => $role]);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function credentials(array $extra = []): array
    {
        return ['email' => 'Grace@Example.com', 'password' => UserFactory::PASSWORD, ...$extra];
    }

    #[Test]
    public function a_single_membership_user_is_signed_in_to_that_tenant(): void
    {
        $response = $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'acme')
            ->assertJsonPath('data.role', 'admin');

        $tokenId = explode('|', (string) $response->json('data.token'))[0];
        $token = PersonalAccessToken::query()->findOrFail($tokenId);
        self::assertSame($this->acme->id, $token->tenant_id);
        self::assertNotNull($token->expires_at);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_id' => $this->user->id]);
    }

    #[Test]
    public function wrong_password_and_unknown_email_get_the_same_answer(): void
    {
        $wrongPassword = $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'nope-nope-nope-1']))
            ->assertUnauthorized()
            ->assertJsonPath('type', 'urn:problem-type:invalid-credentials');

        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123456'])
            ->assertUnauthorized();

        self::assertSame($wrongPassword->json('detail'), $unknown->json('detail'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'actor_id' => $this->user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function several_memberships_require_choosing_a_tenant(): void
    {
        $this->member(Tenant::factory()->create(['slug' => 'globex']), MembershipRole::Staff);

        $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:tenant-selection-required')
            ->assertJsonCount(2, 'tenants');

        $this->postJson('/api/v1/auth/login', $this->credentials(['tenant' => 'globex']))
            ->assertOk()
            ->assertJsonPath('data.tenant.slug', 'globex')
            ->assertJsonPath('data.role', 'staff');
    }

    #[Test]
    public function a_tenant_the_user_does_not_belong_to_is_denied_without_revealing_it_exists(): void
    {
        Tenant::factory()->create(['slug' => 'initech']);

        $existing = $this->postJson('/api/v1/auth/login', $this->credentials(['tenant' => 'initech']))
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:tenant-access-denied');

        $missing = $this->postJson('/api/v1/auth/login', $this->credentials(['tenant' => 'no-such-tenant']))
            ->assertForbidden();

        self::assertSame($existing->json('detail'), $missing->json('detail'));
    }

    #[Test]
    public function a_suspended_tenant_cannot_be_signed_in_to(): void
    {
        $this->acme->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:tenant-suspended');
    }

    #[Test]
    public function login_is_throttled_per_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $this->credentials(['password' => 'wrong-password-'.$i]))->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $this->credentials())
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', 'urn:problem-type:rate-limited');
    }
}
