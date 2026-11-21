<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The tenant an authenticated principal acts as: the tenant its token is bound
 * to, provided the user still belongs to it. Removing a membership therefore
 * cuts off every token for that tenant at once.
 */
final class ResolvePrincipalTenant
{
    public function __invoke(?Authenticatable $principal): ?string
    {
        if (! $principal instanceof User) {
            return null;
        }

        $token = $principal->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $isMember = Membership::query()
            ->where('user_id', $principal->id)
            ->where('tenant_id', $token->tenant_id)
            ->exists();

        return $isMember ? $token->tenant_id : null;
    }
}
