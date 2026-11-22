<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\RolePermissions;
use Illuminate\Http\JsonResponse;

/**
 * The permission matrix, so clients can show or hide actions without
 * hard-coding it.
 */
final class RoleController
{
    public function __invoke(): JsonResponse
    {
        $roles = array_map(static fn (MembershipRole $role): array => [
            'role' => $role->value,
            'permissions' => array_map(static fn (Permission $p): string => $p->value, RolePermissions::for($role)),
        ], MembershipRole::cases());

        return new JsonResponse(['data' => $roles]);
    }
}
