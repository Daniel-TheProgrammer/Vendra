<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * A user's role inside one tenant. The permission matrix behind each role is
 * defined in the RBAC part of this module.
 */
enum MembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Staff = 'staff';
    case ReadOnly = 'read_only';
}
