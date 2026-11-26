<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Services;

/**
 * Signing secrets: 32 random bytes, base64, prefixed so they are easy to
 * recognise (and to catch in secret scanners).
 */
final class SigningSecrets
{
    public const PREFIX = 'whsec_';

    public static function generate(): string
    {
        return self::PREFIX.base64_encode(random_bytes(32));
    }
}
