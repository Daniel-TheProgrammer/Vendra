<?php

declare(strict_types=1);

namespace App\Shared\Tenancy\Rls;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Row-level security for tenant-owned tables. Call from migrations:
 *
 *   Schema::create('orders', function (Blueprint $table) { ... $table->tenantId(); ... });
 *   TenantRls::enable('orders');
 *
 * The policy compares tenant_id with the app.tenant_id session setting that
 * SyncTenantSessionVariable keeps in step with TenantContext. With no tenant
 * set the setting is empty, the comparison is NULL and no row matches: a
 * forgotten Eloquent scope returns nothing instead of leaking.
 *
 * FORCE makes the policy apply to the table owner as well, which is the role
 * the application connects as. Only superusers and BYPASSRLS roles see
 * everything, and the application never connects as one of those.
 */
final class TenantRls
{
    public const SETTING = 'app.tenant_id';

    public const PLATFORM_MARKER = 'vendra:platform-table';

    public static function enable(string $table): void
    {
        $quoted = self::quote($table);
        $policy = self::quote($table.'_tenant_isolation');
        $predicate = sprintf("tenant_id = NULLIF(current_setting('%s', true), '')::uuid", self::SETTING);

        DB::statement(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $quoted));
        DB::statement(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $quoted));
        DB::statement(sprintf('CREATE POLICY %s ON %s USING (%s) WITH CHECK (%s)', $policy, $quoted, $predicate, $predicate));
    }

    /**
     * Declares a table that carries tenant_id but is deliberately NOT under RLS,
     * because it must be read before a tenant is known or across tenants
     * (memberships, access tokens, the audit trail). The marker lives in the
     * table comment, so the declaration is visible in the schema itself and the
     * RLS guard test can honour it. Every use needs a reason a reviewer can judge.
     */
    public static function markPlatformTable(string $table, string $reason): void
    {
        DB::statement(sprintf(
            'COMMENT ON TABLE %s IS %s',
            self::quote($table),
            DB::getPdo()->quote(self::PLATFORM_MARKER.': '.$reason),
        ));
    }

    public static function disable(string $table): void
    {
        $quoted = self::quote($table);
        $policy = self::quote($table.'_tenant_isolation');

        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', $policy, $quoted));
        DB::statement(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $quoted));
        DB::statement(sprintf('ALTER TABLE %s DISABLE ROW LEVEL SECURITY', $quoted));
    }

    private static function quote(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid PostgreSQL identifier.', $identifier));
        }

        return '"'.$identifier.'"';
    }
}
