<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id, honouring one supplied by the caller
 * when it is well-formed. The id is echoed in the response, attached to the
 * log context and travels with queued jobs through Laravel's Context.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get(self::HEADER);

        if (! is_string($id) || preg_match(self::PATTERN, $id) !== 1) {
            $id = Str::uuid7()->toString();
        }

        $request->attributes->set(self::ATTRIBUTE, $id);
        Context::add(self::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
