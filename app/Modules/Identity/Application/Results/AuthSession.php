<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Results;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;

/**
 * Outcome of registering, signing in or switching tenant.
 */
final readonly class AuthSession
{
    public function __construct(
        public IssuedToken $token,
        public User $user,
        public TenantSnapshot $tenant,
        public MembershipRole $role,
    ) {}
}
