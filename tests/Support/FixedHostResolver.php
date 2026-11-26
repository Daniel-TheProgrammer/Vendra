<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Webhooks\Application\Security\HostResolver;

/**
 * DNS for tests: names resolve to what the test says, and can be re-pointed
 * mid-test (to simulate DNS rebinding).
 */
final class FixedHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(
        private array $records = [],
    ) {}

    /**
     * @param  list<string>  $addresses
     */
    public function point(string $host, array $addresses): void
    {
        $this->records[strtolower($host)] = $addresses;
    }

    public function resolve(string $host): array
    {
        return $this->records[strtolower($host)] ?? [];
    }
}
