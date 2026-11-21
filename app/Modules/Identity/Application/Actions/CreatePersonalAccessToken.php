<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Results\IssuedToken;
use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;

/**
 * An additional token for the current tenant, e.g. for a CLI or a script.
 */
final readonly class CreatePersonalAccessToken
{
    public function __construct(
        private TokenIssuer $tokens,
        private AuditTrail $audit,
    ) {}

    public function __invoke(User $user, string $tenantId, string $name, ?int $ttlDays): IssuedToken
    {
        $token = $this->tokens->issue($user, $tenantId, $name, $ttlDays);

        $this->audit->record('token.created', $user->id, $tenantId, ['token_id' => $token->id, 'name' => $name]);

        return $token;
    }
}
