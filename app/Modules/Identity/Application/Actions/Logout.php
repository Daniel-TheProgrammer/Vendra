<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;

final readonly class Logout
{
    public function __construct(
        private AuditTrail $audit,
    ) {}

    public function __invoke(User $user, PersonalAccessToken $currentToken): void
    {
        $currentToken->delete();

        $this->audit->record('auth.logout', $user->id, $currentToken->tenant_id, ['token_id' => $currentToken->id]);
    }
}
