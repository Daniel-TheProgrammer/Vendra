<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class ApiKeyAuthenticationTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    #[Test]
    public function a_key_authenticates_as_its_tenant_and_records_use(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'acme']);
        $issued = $this->actingAsApiKey($tenant);

        self::assertMatchesRegularExpression('/^vendra_sk_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $issued->plainTextKey);

        $this->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', 'acme');

        self::assertNotNull($issued->key->fresh()?->last_used_at);
        self::assertNotSame($issued->plainTextKey, $issued->key->secret_hash);
    }

    #[Test]
    public function a_wrong_secret_with_a_valid_prefix_is_rejected(): void
    {
        $issued = $this->actingAsApiKey();
        $forged = $issued->key->prefix.'_'.str_repeat('A', 40);

        $this->forgetAuthenticatedUser();
        $this->withToken($forged)->getJson('/api/v1/tenant')->assertUnauthorized();
    }

    #[Test]
    public function revoked_and_expired_keys_are_rejected(): void
    {
        $issued = $this->actingAsApiKey(expiresAt: now()->toImmutable()->addDay());
        $this->getJson('/api/v1/tenant')->assertOk();

        $this->travel(2)->days();
        $this->forgetAuthenticatedUser();
        $this->getJson('/api/v1/tenant')->assertUnauthorized();

        $this->travelBack();
        ApiKey::query()->whereKey($issued->key->id)->update(['revoked_at' => now(), 'expires_at' => null]);
        $this->forgetAuthenticatedUser();
        $this->getJson('/api/v1/tenant')->assertUnauthorized();
    }

    #[Test]
    public function a_key_without_the_route_scope_is_forbidden(): void
    {
        $this->actingAsApiKey(scopes: [ApiScope::OrdersRead]);

        $this->getJson('/api/v1/tenant')
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:action-not-permitted');
    }

    #[Test]
    public function keys_cannot_reach_user_only_endpoints(): void
    {
        $this->actingAsApiKey();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/api-keys')->assertUnauthorized();
        $this->postJson('/api/v1/api-keys', ['name' => 'escalate', 'scopes' => ['tenant:read']])->assertUnauthorized();
    }

    #[Test]
    public function a_key_for_a_suspended_tenant_is_refused(): void
    {
        $this->actingAsApiKey(Tenant::factory()->suspended()->create());

        $this->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('type', 'urn:problem-type:tenant-suspended');
    }
}
