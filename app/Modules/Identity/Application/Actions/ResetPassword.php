<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Commands\ResetPasswordCommand;
use App\Modules\Identity\Domain\InvalidPasswordReset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Support\Str;

/**
 * Sets a new password from a reset token and revokes every existing token,
 * so a stolen session cannot survive a password reset.
 */
final readonly class ResetPassword
{
    public function __construct(
        private PasswordBrokerFactory $brokers,
        private AuditTrail $audit,
    ) {}

    public function __invoke(ResetPasswordCommand $command): void
    {
        $status = $this->brokers->broker()->reset(
            [
                'email' => mb_strtolower(trim($command->email)),
                'token' => $command->token,
                'password' => $command->password,
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $revoked = $user->tokens()->delete();

                $this->audit->record('auth.password_reset', $user->id, metadata: ['revoked_tokens' => $revoked]);
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw InvalidPasswordReset::create();
        }
    }
}
