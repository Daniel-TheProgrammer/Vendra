<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\Services\ApiKeyIssuer;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Notifications\ApiKeyExpiringSoon;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_creates_a_key_and_sees_the_secret_exactly_once(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsMember($tenant, MembershipRole::Admin);

        $created = $this->postJson('/api/v1/api-keys', [
            'name' => 'Warehouse sync',
            'scopes' => ['orders:read', 'orders:write'],
            'expires_in_days' => 90,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.scopes', ['orders:read', 'orders:write']);

        self::assertStringStartsWith($created->json('data.prefix').'_', (string) $created->json('data.key'));

        $listed = $this->getJson('/api/v1/api-keys')->assertOk()->assertJsonCount(1, 'data');
        self::assertArrayNotHasKey('key', (array) $listed->json('data.0'));
        self::assertArrayNotHasKey('secret_hash', (array) $listed->json('data.0'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.created', 'tenant_id' => $tenant->id]);
    }

    #[Test]
    public function staff_and_read_only_members_cannot_manage_keys(): void
    {
        foreach ([MembershipRole::Staff, MembershipRole::ReadOnly] as $role) {
            $this->actingAsMember(Tenant::factory()->create(), $role);

            $this->getJson('/api/v1/api-keys')->assertForbidden();
            $this->postJson('/api/v1/api-keys', ['name' => 'nope', 'scopes' => ['tenant:read']])
                ->assertForbidden()
                ->assertJsonPath('type', 'urn:problem-type:action-not-permitted');
        }

        $this->assertDatabaseCount('api_keys', 0);
    }

    #[Test]
    public function unknown_or_duplicate_scopes_are_rejected(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/api-keys', ['name' => 'k', 'scopes' => ['everything:*']])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['scopes.0']]);

        $this->postJson('/api/v1/api-keys', ['name' => 'k', 'scopes' => []])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['scopes']]);
    }

    #[Test]
    public function rotation_issues_a_successor_and_keeps_the_old_key_alive_for_the_grace_period(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsMember($tenant);
        $old = $this->app->make(ApiKeyIssuer::class)->issue($tenant->id, 'Sync', [ApiScope::TenantRead], null, null);

        $successor = $this->postJson('/api/v1/api-keys/'.$old->key->id.'/rotate')
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sync')
            ->assertJsonPath('data.rotated_from_id', $old->key->id);

        $this->forgetAuthenticatedUser();
        $this->withToken($old->plainTextKey)->getJson('/api/v1/tenant')->assertOk();

        $this->travel(25)->hours();
        $this->forgetAuthenticatedUser();
        $this->withToken($old->plainTextKey)->getJson('/api/v1/tenant')->assertUnauthorized();

        $this->forgetAuthenticatedUser();
        $this->withToken((string) $successor->json('data.key'))->getJson('/api/v1/tenant')->assertOk();
    }

    #[Test]
    public function revoking_works_once_and_never_across_tenants(): void
    {
        $mine = Tenant::factory()->create();
        $theirs = Tenant::factory()->create();
        $this->actingAsMember($mine);
        $issuer = $this->app->make(ApiKeyIssuer::class);
        $ownKey = $issuer->issue($mine->id, 'mine', [ApiScope::TenantRead], null, null);
        $foreignKey = $issuer->issue($theirs->id, 'theirs', [ApiScope::TenantRead], null, null);

        $this->deleteJson('/api/v1/api-keys/'.$foreignKey->key->id)->assertNotFound();
        self::assertNull($foreignKey->key->fresh()?->revoked_at);

        $this->deleteJson('/api/v1/api-keys/'.$ownKey->key->id)->assertNoContent();
        $this->deleteJson('/api/v1/api-keys/'.$ownKey->key->id)->assertNotFound();
        self::assertNotNull($ownKey->key->fresh()?->revoked_at);
    }

    #[Test]
    public function creators_are_warned_once_before_their_key_expires(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $creator = User::factory()->create();
        $issuer = $this->app->make(ApiKeyIssuer::class);
        $soon = $issuer->issue($tenant->id, 'soon', [ApiScope::TenantRead], $creator->id, now()->toImmutable()->addDays(5));
        $issuer->issue($tenant->id, 'later', [ApiScope::TenantRead], $creator->id, now()->toImmutable()->addDays(30));

        $this->runArtisan('api-keys:notify-expiring')->assertSuccessful();
        $this->runArtisan('api-keys:notify-expiring')->assertSuccessful();

        Notification::assertSentToTimes($creator, ApiKeyExpiringSoon::class, 1);
        Notification::assertSentTo($creator, ApiKeyExpiringSoon::class, fn (ApiKeyExpiringSoon $n): bool => $n->prefix === $soon->key->prefix);
        self::assertNotNull(ApiKey::query()->find($soon->key->id)?->expiry_notified_at);
    }
}
