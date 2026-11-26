<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Security;

/**
 * Hostname → IP addresses. DNS in production; a fixed table in tests.
 */
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the name resolves to; empty if none.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
