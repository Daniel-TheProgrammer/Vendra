<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Services;

/**
 * How Vendra signs deliveries (docs/webhooks.md):
 *
 *   Webhook-Signature: t=1764230400,v1=<hex>[,v1=<hex>]
 *
 * Each v1 is HMAC-SHA256 of "{t}.{raw body}" under one of the
 * subscription's signing secrets (two while a secret is being rotated). A
 * receiver accepts the delivery when any v1 matches its secret and t is
 * recent; the timestamp inside the signature stops replays.
 */
final class WebhookSignature
{
    public const HEADER = 'Webhook-Signature';

    public const TOLERANCE_SECONDS = 300;

    /**
     * @param  non-empty-list<string>  $secrets
     */
    public static function header(string $body, array $secrets, int $timestamp): string
    {
        $parts = ['t='.$timestamp];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        }

        return implode(',', $parts);
    }

    /**
     * The receiver's side, as documented; used by the tests to verify what
     * was sent the way a subscriber would.
     */
    public static function verify(string $body, string $header, string $secret, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
