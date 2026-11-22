<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Results;

use App\Modules\Identity\Infrastructure\Models\ApiKey;

/**
 * A freshly issued key. The plain-text value exists only here.
 */
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        public string $plainTextKey,
    ) {}
}
