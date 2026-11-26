<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

/**
 * Which addresses a webhook may be delivered to: only the public internet.
 *
 * Everything else is refused: loopback, private networks, link-local (which
 * holds cloud metadata services at 169.254.169.254), carrier-grade NAT,
 * documentation, benchmarking, multicast and reserved ranges, for IPv4 and
 * IPv6, including IPv4 addresses smuggled inside IPv6 (mapped, NAT64).
 * A webhook URL is chosen by a tenant, so it must never reach Vendra's
 * own network (SSRF).
 */
final class IpAddress
{
    /**
     * @var list<string>
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // private
        '100.64.0.0/10',      // carrier-grade NAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local, cloud metadata
        '172.16.0.0/12',      // private
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // documentation
        '192.88.99.0/24',     // 6to4 relay
        '192.168.0.0/16',     // private
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // documentation
        '203.0.113.0/24',     // documentation
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved, broadcast
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '100::/64',           // discard
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local (private)
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    /**
     * IPv6 prefixes that carry an IPv4 address in their last 32 bits.
     *
     * @var list<string>
     */
    private const EMBEDS_V4 = [
        '::ffff:0:0/96',      // IPv4-mapped
        '64:ff9b::/96',       // NAT64
        '::/96',              // IPv4-compatible (deprecated)
    ];

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return ! self::inAny($packed, self::BLOCKED_V4);
        }

        foreach (self::EMBEDS_V4 as $prefix) {
            if (self::inRange($packed, $prefix)) {
                return self::isPublic((string) inet_ntop(substr($packed, 12)));
            }
        }

        return ! self::inAny($packed, self::BLOCKED_V6);
    }

    /**
     * @param  list<string>  $ranges
     */
    private static function inAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $base = (string) inet_pton($network);

        if (strlen($base) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
