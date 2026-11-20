<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API only speaks JSON. Forcing the Accept header keeps framework
 * responses (validation, authentication, 404s) on the JSON path regardless of
 * what the client sent. Non-API paths such as /up are left untouched.
 */
final class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
