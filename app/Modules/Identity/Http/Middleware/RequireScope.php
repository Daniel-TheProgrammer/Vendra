<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Domain\ActionNotPermitted;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "scope:orders:write". API keys must hold the scope.
 * Users pass through: what a person may do is decided by their role.
 */
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $required = ApiScope::tryFrom($scope) ?? throw new InvalidArgumentException(sprintf('Unknown API scope "%s" on route.', $scope));
        $principal = $request->user();

        if ($principal instanceof ApiKey && ! $principal->allows($required)) {
            throw ActionNotPermitted::missingScope($required);
        }

        return $next($request);
    }
}
