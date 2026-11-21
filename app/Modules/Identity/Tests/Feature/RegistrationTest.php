<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => 'analytical-engine-1843',
            'password_confirmation' => 'analytical-engine-1843',
            'tenant_name' => 'Analytical Engines',
            ...$overrides,
        ];
    }

    #[Test]
    public function registering_creates_user_tenant_owner_membership_and_a_working_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.tenant.slug', 'analytical-engines')
            ->assertJsonPath('data.role', 'owner');

        $token = $response->json('data.token');
        self::assertIsString($token);
        self::assertStringContainsString('|vendra_pat_', $token);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'analytical-engines')->firstOrFail();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.registered', 'actor_id' => $user->id, 'tenant_id' => $tenant->id]);
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->forgetAuthenticatedUser();
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id);
    }

    #[Test]
    public function the_password_is_stored_as_an_argon2id_hash(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $hash = User::query()->where('email', 'ada@example.com')->value('password');
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
    }

    #[Test]
    public function an_existing_email_is_a_conflict_and_nothing_is_created(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:email-already-registered');

        $this->assertDatabaseCount('tenants', 0);
    }

    #[Test]
    public function a_taken_tenant_slug_rolls_back_the_user(): void
    {
        Tenant::factory()->create(['slug' => 'analytical-engines']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:tenant-slug-taken');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    #[Test]
    public function weak_or_unconfirmed_passwords_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['password']]);

        $this->postJson('/api/v1/auth/register', $this->payload(['password_confirmation' => 'something-else-123']))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['password']]);
    }
}
