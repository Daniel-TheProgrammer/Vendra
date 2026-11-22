<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Identity\Domain\ApiScope;

final readonly class CreateApiKeyCommand
{
    /**
     * @param  list<ApiScope>  $scopes
     */
    public function __construct(
        public string $tenantId,
        public string $actorId,
        public string $name,
        public array $scopes,
        public ?int $ttlDays,
    ) {}
}
