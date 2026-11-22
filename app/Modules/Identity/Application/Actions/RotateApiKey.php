<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Results\IssuedApiKey;
use App\Modules\Identity\Application\Services\ApiKeyIssuer;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Issues a successor with the same name, scopes and lifetime, and lets the
 * old key live for a grace period so running deployments can switch over.
 */
final readonly class RotateApiKey
{
    public function __construct(
        private ApiKeyIssuer $issuer,
        private TransactionRunner $transaction,
        private Clock $clock,
        private Config $config,
        private AuditTrail $audit,
    ) {}

    public function __invoke(string $actorId, string $tenantId, string $keyId): IssuedApiKey
    {

        return $this->transaction->run(function () use ($actorId, $tenantId, $keyId): IssuedApiKey {
            $now = $this->clock->now();
            $old = ApiKey::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($keyId)
                ->lockForUpdate()
                ->first();

            if (! $old instanceof ApiKey || ! $old->isUsableAt($now)) {
                throw EntityNotFound::withId('API key', $keyId);
            }

            $lifetime = $old->expires_at !== null && $old->created_at !== null
                ? $old->created_at->diff($old->expires_at)
                : null;

            $issued = $this->issuer->issue(
                tenantId: $tenantId,
                name: $old->name,
                scopes: array_values(array_filter(array_map(ApiScope::tryFrom(...), $old->scopes))),
                createdBy: $actorId,
                expiresAt: $lifetime === null ? null : $now->add($lifetime),
                rotatedFromId: $old->id,
            );

            $graceEndsAt = $now->addHours($this->graceHours());

            if ($old->expires_at === null || $old->expires_at->isAfter($graceEndsAt)) {
                $old->forceFill(['expires_at' => $graceEndsAt])->save();
            }

            $this->audit->record('api_key.rotated', $actorId, $tenantId, [
                'api_key_id' => $issued->key->id,
                'previous_api_key_id' => $old->id,
                'previous_expires_at' => $old->expires_at?->toIso8601String(),
            ]);

            return $issued;
        });
    }

    private function graceHours(): int
    {
        $value = $this->config->get('identity.api_keys.rotation_grace_hours', 24);

        return is_numeric($value) ? max(0, (int) $value) : 24;
    }
}
