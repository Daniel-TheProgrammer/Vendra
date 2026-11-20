# 0004. Row-level security as defence in depth

Date: 2026-11-20
Status: Accepted

## Context

ADR 0002 chose a single database with a `tenant_id` column per tenant table. The
Eloquent global scope added in Phase 1 filters every model query, but a scope is
application code: a raw query, a `DB::table()` call, a query inside a package or
a future refactor can all skip it. For a billing system the cost of one such
mistake is a cross-tenant data leak, so the database itself must refuse to
return or accept rows that belong to another tenant.

## Decision

Every tenant-owned table gets a PostgreSQL row-level security policy, created by
`TenantRls::enable()` from the table's migration:

```sql
ALTER TABLE t ENABLE ROW LEVEL SECURITY;
ALTER TABLE t FORCE ROW LEVEL SECURITY;
CREATE POLICY t_tenant_isolation ON t
    USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
```

- **Roles.** The application connects as a role that is neither a superuser nor
  `BYPASSRLS`. That role owns the tables, which is why `FORCE` is required:
  without it, owners are exempt from their own policies. The superuser is used
  only to initialise the server and by the separate `pgsql_admin` connection.
- **Session setting.** `SyncTenantSessionVariable` sets `app.tenant_id` on every
  open connection whenever `TenantContext` changes, when a connection is
  established (including reconnects) and when Laravel's Context is hydrated on
  a queue worker. With no tenant set the value is empty, the predicate is NULL
  and no row matches.
- **Cross-tenant work** is done deliberately on the `pgsql_admin` connection
  from console commands, never from HTTP. The Eloquent `withoutTenancy()`
  scope only removes the application filter; the database policy still applies
  on the normal connection.
- **A test guards the invariant**: any table in `public` with a `tenant_id`
  column but without forced RLS and a policy fails the suite.

## Alternatives considered

- **Application scopes only.** Rejected in ADR 0002; one forgotten `where` is a
  breach.
- **A database role per tenant.** Strong, but connection pooling and role
  management do not scale to thousands of tenants.
- **Policies without FORCE and a separate non-owner app role.** Works, but
  requires granting privileges table by table after every migration and makes
  local setup fragile. FORCE keeps the role model to one application role.

## Consequences

- Isolation holds even for raw SQL, packages and future code.
- The session setting is connection-scoped, so transaction-pooling proxies such
  as PgBouncer in transaction mode are unsupported in front of this application.
  Session pooling or a direct connection is required.
- Tests must run as the restricted role; CI and the local Compose stack create
  it the same way. Running the suite as a superuser makes the RLS tests fail on
  purpose.
- Every migration that creates a tenant table must call `TenantRls::enable()`;
  the guard test catches omissions.
