<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

/**
 * Append-only record of security-relevant actions: who did what, as which
 * tenant, from where. Entries are never updated or deleted by the application.
 */
interface AuditTrail
{
    /**
     * @param  string  $action  dot-separated verb such as "auth.login" or "token.revoked"
     * @param  array<string, scalar|null|array<array-key, mixed>>  $metadata  must not contain secrets
     */
    public function record(string $action, ?string $actorId = null, ?string $tenantId = null, array $metadata = []): void;
}
