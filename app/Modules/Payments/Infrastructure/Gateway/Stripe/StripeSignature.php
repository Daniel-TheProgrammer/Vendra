<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateway\Stripe;

/**
 * Stripe's webhook signature scheme.
 *
 *   Stripe-Signature: t=1764061200,v1=5257a869…,v1=…
 *
 * Each v1 is HMAC-SHA256 of "{t}.{raw body}" under an endpoint secret (Stripe
 * sends one per secret while a secret is being rolled). A payload verifies
 * when any v1 matches any accepted secret, compared in constant time, and t
 * is within the tolerance of now: an old, captured request cannot be
 * replayed later.
 */
final class StripeSignature
{
    public const HEADER = 'Stripe-Signature';

    public const TOLERANCE_SECONDS = 300;

    /**
     * @param  list<string>  $secrets
     */
    public static function verify(string $payload, ?string $header, array $secrets, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if ($header === null || $header === '' || $secrets === []) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        foreach ($secrets as $secret) {
            $expected = self::compute($payload, $secret, $timestamp);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A header as Stripe would send it: for tests, and for replaying a
     * stored event against a local endpoint.
     */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, self::compute($payload, $secret, $timestamp));
    }

    private static function compute(string $payload, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
