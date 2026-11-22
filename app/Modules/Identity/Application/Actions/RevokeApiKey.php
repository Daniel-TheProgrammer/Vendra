<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Revocation is immediate and permanent. The row is kept for the audit trail.
 */
final readonly class RevokeApiKey
{
    public function __construct(
        private Clock $clock,
        private AuditTrail $audit,
    ) {}

    public function __invoke(string $actorId, string $tenantId, string $keyId): void
    {

        $key = ApiKey::query()->where('tenant_id', $tenantId)->whereKey($keyId)->first();

        if (! $key instanceof ApiKey || $key->revoked_at !== null) {
            throw EntityNotFound::withId('API key', $keyId);
        }

        $key->forceFill(['revoked_at' => $this->clock->now()])->save();

        $this->audit->record('api_key.revoked', $actorId, $tenantId, ['api_key_id' => $key->id, 'prefix' => $key->prefix]);
    }
}
