<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Identity\Application\Queries\ResolvePrincipalTenant;
use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Modules\Tenancy\Domain\TenantSuspended;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request from the authenticated principal and
 * clears it afterwards. Must run after authentication. The client never names
 * the tenant: it is whatever the presented token is bound to, and only while
 * the user is still a member of it.
 */
final readonly class ResolveTenant
{
    public function __construct(
        private ResolvePrincipalTenant $principalTenant,
        private FindTenant $findTenant,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = ($this->principalTenant)($request->user());
        $tenant = $tenantId === null ? null : ($this->findTenant)($tenantId);

        if (! $tenant instanceof TenantSnapshot) {
            throw new AuthenticationException('The request could not be associated with a tenant.');
        }

        if (! $tenant->isActive()) {
            throw TenantSuspended::withSlug($tenant->slug);
        }

        $this->context->set($tenant->id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
