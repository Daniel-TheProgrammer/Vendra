<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signedLink(User $user, ?string $hash = null): string
    {
        return URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => $hash ?? sha1($user->email),
        ]);
    }

    #[Test]
    public function a_valid_signed_link_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->signedLink($user))->assertOk()->assertJsonPath('data.verified', true);

        self::assertTrue($user->fresh()?->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified', 'actor_id' => $user->id]);
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->signedLink($user).'x')->assertForbidden();
        $this->getJson($this->signedLink($user, sha1('someone-else@example.com')))->assertNotFound();

        self::assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    #[Test]
    public function an_unverified_user_can_ask_for_a_new_link(): void
    {
        Notification::fake();
        $user = $this->actingAsMember(Tenant::factory()->create(), user: User::factory()->unverified()->create());

        $this->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
