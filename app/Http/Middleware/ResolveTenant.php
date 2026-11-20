<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Modules\Tenancy\Domain\TenantSuspended;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request and clears it afterwards.
 *
 * Phase 1: the tenant comes from an X-Tenant-Id header (id or slug) and only
 * in local and testing environments. Phase 2 derives it from the authenticated
 * principal; the header path is removed then, never widened.
 */
final readonly class ResolveTenant
{
    public const HEADER = 'X-Tenant-Id';

    public function __construct(
        private Application $app,
        private FindTenant $findTenant,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

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

    private function resolve(Request $request): ?TenantSnapshot
    {
        if (! $this->app->environment('local', 'testing')) {
            return null;
        }

        $identifier = $request->headers->get(self::HEADER);

        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        return ($this->findTenant)($identifier);
    }
}
