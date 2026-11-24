<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads the version a client expects from If-Match (ADR 0006). Versioned
 * resources send ETag: "<version>"; a client echoes it to make its write
 * conditional. Absent or "*" means unconditional (last write wins).
 */
final class IfMatch
{
    public static function expectedVersion(Request $request): ?int
    {
        $header = $request->header('If-Match');

        if (! is_string($header) || trim($header) === '' || trim($header) === '*') {
            return null;
        }

        if (preg_match('/^(?:W\/)?"(\d{1,9})"$/', trim($header), $match) !== 1) {
            throw new BadRequestHttpException('If-Match must be a single ETag previously returned for this resource, e.g. "3".');
        }

        return (int) $match[1];
    }
}
