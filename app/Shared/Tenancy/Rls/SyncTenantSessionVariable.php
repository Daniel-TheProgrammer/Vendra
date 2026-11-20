<?php

declare(strict_types=1);

namespace App\Shared\Tenancy\Rls;

use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextChanged;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\PostgresConnection;

/**
 * Keeps the PostgreSQL session setting read by the RLS policies equal to the
 * tenant in TenantContext, on every open connection, at three moments:
 *
 *  - the tenant changes (request start/end, tenant:run, runAs);
 *  - a connection is established, including reconnects;
 *  - Context is hydrated on a queue worker before a job runs.
 *
 * The setting is session-scoped, so connection poolers in transaction mode
 * (PgBouncer) are not supported in front of this application.
 */
final readonly class SyncTenantSessionVariable
{
    public function __construct(
        private DatabaseManager $db,
        private TenantContext $context,
    ) {}

    public function onTenantChanged(TenantContextChanged $event): void
    {
        foreach ($this->db->getConnections() as $connection) {
            $this->apply($connection, $event->tenantId);
        }
    }

    public function onConnectionEstablished(ConnectionEstablished $event): void
    {
        $this->apply($event->connection, $this->context->idOrNull());
    }

    /**
     * Re-applies the current context to every open connection.
     */
    public function sync(): void
    {
        $this->onTenantChanged(new TenantContextChanged($this->context->idOrNull()));
    }

    private function apply(Connection $connection, ?string $tenantId): void
    {
        if (! $connection instanceof PostgresConnection) {
            return;
        }

        $connection->statement(
            sprintf("SELECT set_config('%s', ?, false)", TenantRls::SETTING),
            [$tenantId ?? ''],
        );
    }
}
