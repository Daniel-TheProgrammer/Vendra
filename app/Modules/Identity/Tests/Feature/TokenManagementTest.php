<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class TokenManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_token_is_shown_once_and_authenticates(): void
    {
        $this->actingAsMember();

        $response = $this->postJson('/api/v1/auth/tokens', ['name' => 'ci-deploy', 'expires_in_days' => 7])
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer');

        $plain = $response->json('data.token');
        self::assertIsString($plain);

        $stored = PersonalAccessToken::query()->findOrFail((string) $response->json('data.id'));
        self::assertNotSame($plain, $stored->token, 'only a hash may be stored');
        self::assertSame(7, (int) round(now()->diffInDays($stored->expires_at)));

        $this->forgetAuthenticatedUser();
        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
    }

    #[Test]
    public function listing_shows_this_tenants_tokens_without_secrets_and_marks_the_current_one(): void
    {
        $acme = Tenant::factory()->create();
        $user = $this->actingAsMember($acme);
        $this->app->make(TokenIssuer::class)->issue($user, $acme->id, 'laptop');

        $other = Tenant::factory()->create();
        $this->actingAsMember($other, user: $user);
        $this->app->make(TokenIssuer::class)->issue($user, $other->id, 'other-tenant');

        $response = $this->getJson('/api/v1/auth/tokens')->assertOk()->assertJsonCount(2, 'data');

        $tokens = $response->json('data');
        self::assertIsArray($tokens);

        $names = array_column($tokens, 'name');
        sort($names);
        self::assertSame(['other-tenant', 'tests'], $names);
        self::assertSame([true], array_values(array_filter(array_column($tokens, 'current'))));

        foreach ($tokens as $token) {
            self::assertIsArray($token);
            self::assertArrayNotHasKey('token', $token);
        }
    }

    #[Test]
    public function a_token_can_be_revoked_but_not_one_from_another_tenant(): void
    {
        $acme = Tenant::factory()->create();
        $globex = Tenant::factory()->create();
        $user = $this->actingAsMember($acme);
        $mine = $this->app->make(TokenIssuer::class)->issue($user, $acme->id, 'laptop');
        $foreign = $this->app->make(TokenIssuer::class)->issue($user, $globex->id, 'globex-laptop');

        $this->deleteJson('/api/v1/auth/tokens/'.$foreign->id)->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);

        $this->deleteJson('/api/v1/auth/tokens/'.$mine->id)->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mine->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'token.revoked', 'actor_id' => $user->id]);
    }

    #[Test]
    public function lifetimes_beyond_the_maximum_are_rejected(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/auth/tokens', ['name' => 'forever', 'expires_in_days' => 10_000])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['expires_in_days']]);
    }
}
