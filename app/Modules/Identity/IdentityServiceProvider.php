<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Identity';
    }

    public function boot(): void
    {
        parent::boot();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->configurePasswords();
        $this->configureRateLimits();
        $this->configureEmailLinks();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(12)->max(128)->letters()->numbers();

            // The breach check calls an external API; keep it out of tests and local runs.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $perMinute = config('identity.login_attempts_per_minute', 5);
            $email = mb_strtolower(trim($request->string('email')->value()));

            return Limit::perMinute(is_numeric($perMinute) ? (int) $perMinute : 5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth', static fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
    }

    /**
     * The API has no HTML pages: verification links point at the signed API
     * route, reset links at the client application.
     */
    private function configureEmailLinks(): void
    {
        VerifyEmail::createUrlUsing(static fn (User $user): string => URL::temporarySignedRoute(
            'api.v1.auth.verification.verify',
            now()->addHour(),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        ));

        ResetPassword::createUrlUsing(static function (User $user, string $token): string {
            $base = config('app.frontend_url', 'http://localhost:3000');

            return sprintf(
                '%s/reset-password?%s',
                rtrim(is_string($base) ? $base : '', '/'),
                http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]),
            );
        });
    }
}
