<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Results;

use Carbon\CarbonImmutable;

/**
 * A freshly issued token. The plain-text value exists only here: it is shown
 * to the client once and only its hash is stored.
 */
final readonly class IssuedToken
{
    public function __construct(
        public string $id,
        public string $plainTextToken,
        public string $tenantId,
        public CarbonImmutable $expiresAt,
    ) {}
}
