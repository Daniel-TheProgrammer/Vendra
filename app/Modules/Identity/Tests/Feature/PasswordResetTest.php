<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function forgot_password_answers_the_same_for_known_and_unknown_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'linus@example.com']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'linus@example.com'])->assertAccepted();
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertAccepted();

        self::assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    #[Test]
    public function resetting_sets_the_new_password_and_revokes_every_token(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create();
        $user = $this->actingAsMember($tenant, user: User::factory()->create(['email' => 'linus@example.com']));

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'linus@example.com'])->assertAccepted();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'linus@example.com',
            'token' => $resetToken,
            'password' => 'brand-new-secret-42',
            'password_confirmation' => 'brand-new-secret-42',
        ])->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->forgetAuthenticatedUser();
        $this->postJson('/api/v1/auth/login', ['email' => 'linus@example.com', 'password' => 'brand-new-secret-42'])->assertOk();
    }

    #[Test]
    public function an_invalid_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'linus@example.com']);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => 'linus@example.com',
            'token' => 'not-a-real-token',
            'password' => 'brand-new-secret-42',
            'password_confirmation' => 'brand-new-secret-42',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:invalid-password-reset');
    }
}
