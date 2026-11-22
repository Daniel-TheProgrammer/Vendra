<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Application\Services\Authorizer;
use App\Modules\Identity\Domain\Permission;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "permission:orders.manage". Runs after "tenant", so the
 * tenant is known. Covers people (by role) and API keys (by scope).
 */
final readonly class RequirePermission
{
    public function __construct(
        private Authorizer $authorizer,
        private TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $required = Permission::tryFrom($permission)
            ?? throw new InvalidArgumentException(sprintf('Unknown permission "%s" on route.', $permission));

        $this->authorizer->ensure($request->user(), $this->context->id(), $required);

        return $next($request);
    }
}
