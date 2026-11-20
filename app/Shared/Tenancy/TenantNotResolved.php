<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use LogicException;

/**
 * Tenant-scoped code ran without a tenant. This is a programming error: HTTP
 * requests get their tenant from middleware, jobs from the dispatching context
 * and console commands from tenant:run.
 */
final class TenantNotResolved extends LogicException
{
    public static function create(): self
    {
        return new self('No tenant is set for the current context.');
    }
}
