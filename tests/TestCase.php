<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /**
     * Like a well-behaved client SDK, every POST gets a fresh Idempotency-Key
     * unless the test sends its own. Tests of the idempotency contract itself
     * turn this off.
     */
    protected bool $sendIdempotencyKeys = true;

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->sendIdempotencyKeys && strtoupper($method) === 'POST' && ! isset($server['HTTP_IDEMPOTENCY_KEY'])) {
            $server['HTTP_IDEMPOTENCY_KEY'] = (string) Str::uuid7();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Replaces the application clock with one that only moves on demand.
     */
    protected function freezeClock(?CarbonImmutable $at = null): FrozenClock
    {
        $clock = new FrozenClock($at);
        $this->app->instance(Clock::class, $clock);

        return $clock;
    }

    /**
     * Typed variant of artisan() for fluent console assertions.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            self::fail('Console output must be mocked to assert on a command; do not call withoutMockingConsoleOutput().');
        }

        return $pending;
    }

    /**
     * Makes the user a member of the tenant and authenticates the following
     * requests with a real token bound to it, through the real middleware stack.
     */
    protected function actingAsMember(
        ?Tenant $tenant = null,
        MembershipRole $role = MembershipRole::Owner,
        ?User $user = null,
    ): User {
        $tenant ??= Tenant::factory()->create();
        $user ??= User::factory()->create();

        Membership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => $role],
        );

        $token = $this->app->make(TokenIssuer::class)->issue($user, $tenant->id, 'tests');
        $this->withToken($token->plainTextToken);
        $this->forgetAuthenticatedUser();

        return $user;
    }

    /**
     * Guards cache the user they resolved. Call between requests that must be
     * authenticated afresh, e.g. after a token was revoked.
     */
    protected function forgetAuthenticatedUser(): void
    {
        $this->app->make('auth')->forgetGuards();
    }
}
