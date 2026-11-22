<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Queries\CurrentRole;
use App\Modules\Identity\Domain\ActionNotPermitted;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\RolePermissions;
use App\Modules\Identity\Domain\TenantAccessDenied;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The one place that answers "may this principal do this in this tenant".
 *
 * - People: the permission must be in their role's column of the matrix.
 * - API keys: the permission must map to a scope the key holds. Permissions
 *   without a scope are for people only.
 */
final readonly class Authorizer
{
    public function __construct(
        private CurrentRole $currentRole,
    ) {}

    public function ensure(?Authenticatable $principal, string $tenantId, Permission $permission): void
    {
        if ($principal instanceof ApiKey) {
            $scope = $permission->scope() ?? throw ActionNotPermitted::peopleOnly($permission);

            if (! $principal->allows($scope)) {
                throw ActionNotPermitted::missingScope($scope);
            }

            return;
        }

        $role = $this->roleOf($principal, $tenantId);

        if (! RolePermissions::grants($role, $permission)) {
            throw ActionNotPermitted::missingPermission($role, $permission);
        }
    }

    public function roleOf(?Authenticatable $principal, string $tenantId): MembershipRole
    {
        if (! $principal instanceof User) {
            throw TenantAccessDenied::create();
        }

        return ($this->currentRole)($principal->id, $tenantId) ?? throw TenantAccessDenied::create();
    }
}
