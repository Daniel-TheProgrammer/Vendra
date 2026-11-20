<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

/**
 * Fired whenever the current tenant is set or cleared, so infrastructure that
 * mirrors the tenant into other systems (the database session, for one) can
 * follow along.
 */
final readonly class TenantContextChanged
{
    public function __construct(
        public ?string $tenantId,
    ) {}
}
