<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The user is not a member of the requested tenant. The same message is used
 * whether or not the tenant exists, so it cannot be used to probe for tenants.
 */
final class TenantAccessDenied extends DomainException
{
    public static function create(): self
    {
        return new self('You do not have access to this tenant.');
    }

    public function status(): int
    {
        return 403;
    }

    public function type(): string
    {
        return 'tenant-access-denied';
    }

    public function title(): string
    {
        return 'Tenant access denied';
    }
}
