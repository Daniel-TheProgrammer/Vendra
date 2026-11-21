<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class RevokePersonalAccessToken
{
    public function __construct(
        private AuditTrail $audit,
    ) {}

    /**
     * Only the owner may revoke, and only tokens of the tenant they are acting
     * in. Anything else is reported as not found.
     */
    public function __invoke(User $user, string $tenantId, string $tokenId): void
    {
        $token = $user->tokens()
            ->where('id', $tokenId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $token instanceof PersonalAccessToken) {
            throw EntityNotFound::withId('Token', $tokenId);
        }

        $token->delete();

        $this->audit->record('token.revoked', $user->id, $tenantId, ['token_id' => $tokenId]);
    }
}
