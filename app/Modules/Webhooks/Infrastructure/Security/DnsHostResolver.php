<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Security;

use App\Modules\Webhooks\Application\Security\HostResolver;

/**
 * A and AAAA records through the system resolver.
 */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        if ($addresses === []) {
            // dns_get_record skips /etc/hosts and some resolver setups.
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}
