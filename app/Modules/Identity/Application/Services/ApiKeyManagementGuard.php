<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Queries\CurrentRole;
use App\Modules\Identity\Domain\ActionNotPermitted;
use App\Modules\Identity\Domain\MembershipRole;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Who may create, rotate and revoke API keys. Owners and admins only.
 * The RBAC permission matrix takes this over; the call sites stay.
 */
final readonly class ApiKeyManagementGuard
{
    public function __construct(
        private CurrentRole $currentRole,
    ) {}

    public function ensure(string $actorId, string $tenantId): void
    {
        $role = ($this->currentRole)($actorId, $tenantId) ?? throw EntityNotFound::withId('Membership', $actorId);

        if (! in_array($role, [MembershipRole::Owner, MembershipRole::Admin], true)) {
            throw ActionNotPermitted::forRole($role, 'manage API keys');
        }
    }
}
