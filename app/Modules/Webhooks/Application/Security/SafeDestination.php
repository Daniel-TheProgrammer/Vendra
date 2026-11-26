<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Security;

/**
 * A webhook URL that passed the guard, with the public addresses it resolved
 * to. Delivery connects to one of these addresses and no other, so the name
 * cannot be re-pointed at an internal address between the check and the
 * connection (DNS rebinding).
 */
final readonly class SafeDestination
{
    /**
     * @param  non-empty-list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}
}
