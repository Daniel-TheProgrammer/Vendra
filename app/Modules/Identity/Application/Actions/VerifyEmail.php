<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Completes e-mail verification from the signed link sent at registration.
 * The URL signature is checked by middleware; this checks the link was made
 * for the user's current address.
 */
final readonly class VerifyEmail
{
    public function __construct(
        private AuditTrail $audit,
    ) {}

    public function __invoke(string $userId, string $emailHash): void
    {
        $user = User::query()->find($userId);

        if (! $user instanceof User || ! hash_equals(sha1($user->getEmailForVerification()), $emailHash)) {
            throw EntityNotFound::withId('Verification link', $userId);
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->markEmailAsVerified();

        $this->audit->record('auth.email_verified', $user->id);
    }
}
