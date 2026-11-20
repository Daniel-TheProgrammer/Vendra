# 0002. Single database with row-level security for tenancy

Date: 2026-11-20
Status: Accepted

## Context

Every tenant-owned row must be invisible to every other tenant, including when a
developer forgets a query scope. Tenants are numerous and small, so per-tenant
infrastructure must be cheap. Cross-tenant reporting for the platform operator is
occasionally needed. Backups, migrations and connection pooling must stay simple
for a single-engineer team.

## Decision

One PostgreSQL database and one schema. Every tenant-owned table carries a
`tenant_id uuid NOT NULL` column, and isolation is enforced in three layers:

1. **Eloquent global scope** (`BelongsToTenant` trait) filters reads and fills
   `tenant_id` on create from the request's `TenantContext`.
2. **PostgreSQL row-level security.** Each tenant table has a policy
   `USING (tenant_id = current_setting('app.tenant_id')::uuid)`. The application
   connects as a role without `BYPASSRLS`; the setting is applied per
   transaction. A missing scope therefore returns no rows rather than leaking.
3. **Composite foreign keys** `(tenant_id, parent_id)` on child tables, so a
   child can never reference a parent that belongs to another tenant.

Migrations run on a separate connection as the schema owner, which is the only
role allowed to bypass RLS.

## Alternatives considered

- **Database per tenant.** Strongest isolation, but migrations, backups,
  connection pools and cross-tenant reporting multiply by tenant count, and
  onboarding a tenant becomes an infrastructure operation.
- **Schema per tenant.** Same operational cost as database-per-tenant with weaker
  isolation guarantees, and poor fit for connection pooling (search_path per
  connection).
- **Application scopes only.** Cheapest, but a single forgotten `where` becomes a
  data breach. Not acceptable for a billing system.

## Consequences

- Tenant onboarding is an `INSERT`; no infrastructure changes.
- Every tenant table needs the RLS policy and the composite keys; a schema helper
  (`Schema::enableTenantRls()`) makes this one line per migration, and a test
  asserts every tenant table has a policy.
- The application must set `app.tenant_id` on every connection use, including
  queue workers and long-lived processes (Octane). A connection listener and a
  job middleware own this responsibility.
- Tests must run against real PostgreSQL; SQLite has no RLS.
