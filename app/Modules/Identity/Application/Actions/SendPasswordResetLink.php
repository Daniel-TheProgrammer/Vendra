<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Shared\Application\Audit\AuditTrail;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;

/**
 * Always completes without telling the caller whether the address exists;
 * the outcome is visible only in the audit trail.
 */
final readonly class SendPasswordResetLink
{
    public function __construct(
        private PasswordBrokerFactory $brokers,
        private AuditTrail $audit,
    ) {}

    public function __invoke(string $email): void
    {
        $status = $this->brokers->broker()->sendResetLink(['email' => mb_strtolower(trim($email))]);

        $this->audit->record('auth.password_reset_requested', metadata: [
            'outcome' => match ($status) {
                PasswordBroker::RESET_LINK_SENT => 'sent',
                PasswordBroker::RESET_THROTTLED => 'throttled',
                default => 'unknown_email',
            },
        ]);
    }
}
