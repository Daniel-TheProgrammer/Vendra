<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Commands\CreateApiKeyCommand;
use App\Modules\Identity\Application\Results\IssuedApiKey;
use App\Modules\Identity\Application\Services\ApiKeyIssuer;
use App\Modules\Identity\Application\Services\ApiKeyManagementGuard;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;

final readonly class CreateApiKey
{
    public function __construct(
        private ApiKeyManagementGuard $guard,
        private ApiKeyIssuer $issuer,
        private Clock $clock,
        private AuditTrail $audit,
    ) {}

    public function __invoke(CreateApiKeyCommand $command): IssuedApiKey
    {
        $this->guard->ensure($command->actorId, $command->tenantId);

        $issued = $this->issuer->issue(
            tenantId: $command->tenantId,
            name: $command->name,
            scopes: $command->scopes,
            createdBy: $command->actorId,
            expiresAt: $command->ttlDays === null ? null : $this->clock->now()->addDays($command->ttlDays),
        );

        $this->audit->record('api_key.created', $command->actorId, $command->tenantId, [
            'api_key_id' => $issued->key->id,
            'prefix' => $issued->key->prefix,
            'scopes' => $issued->key->scopes,
        ]);

        return $issued;
    }
}
