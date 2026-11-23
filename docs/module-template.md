# Module template

Catalog is the reference module. Every later module (Orders, Ledger, Payments,
Webhooks, Search) copies its layout and conventions. When a convention here
changes, change Catalog too, so the two never disagree.

## Folder layout

```
app/Modules/<Name>/
├── <Name>ServiceProvider.php    extends ModuleServiceProvider; registers routes and migrations
├── Domain/                      public API: no Laravel, no Eloquent, no I/O
│   ├── <ValueObject>.php        immutable, validates in the constructor (StockLevel, PriceWindow)
│   ├── <Enum>.php               backed enums for every closed set of values (ProductStatus)
│   ├── <Rule>Exception.php      extends Shared ConflictException/DomainException: type(), title(), extensions()
│   └── Events/                  readonly DomainEvent classes: ids and versions only, never state
├── Application/                 public API: what controllers, jobs and other modules call
│   ├── Actions/                 one invokable class per write use case; owns the transaction
│   ├── Commands/                readonly input DTOs for actions
│   ├── Queries/                 one invokable class per read use case
│   ├── Services/                collaborators shared by several actions (locks, ledgers)
│   └── <Feature>/               a cohesive sub-feature too big for the folders above (Import/)
├── Infrastructure/              internal
│   ├── Models/                  Eloquent models: BelongsToTenant, UUID v7, immutable dates
│   └── Jobs/                    queue entry points; the work itself lives in Application
├── Http/                        internal
│   ├── routes.php               mounted under /api/v1, named api.v1.*
│   ├── Controllers/             thin: validate, call one action or query, return a resource
│   ├── Requests/                FormRequests that also map input to commands
│   ├── Resources/               extend Shared ApiResource ("data" envelope)
│   └── Concerns/                HTTP behaviour shared by controllers (ETag/If-Match)
├── Database/
│   ├── Migrations/              loaded by the service provider
│   └── Factories/
└── Tests/
    ├── Unit/                    domain rules, no database
    └── Feature/                 HTTP and action tests against PostgreSQL (#[Group('database')])
```

Deptrac (`deptrac.yaml`) enforces the boundary: other modules may use
`Domain` and `Application` only. A new module needs its `<Name>Api` and
`<Name>Internal` layers added there, and its provider added to
`bootstrap/providers.php`.

## Checklist for a new module

**Tables**

- `$table->tenantId()`, then `TenantRls::enable('<table>')` in the same migration.
- `unique(['tenant_id', 'id'])` on parents.
- Children reference `(tenant_id, parent_id)` with a composite foreign key, so
  a row can never point into another tenant.
- Put invariants in the database as well as in code: check constraints,
  partial unique indexes, exclusion constraints and append-only triggers.
- Money is `bigint` minor units, never a float or a decimal string.
- Timestamps are `timestampTz`, written in UTC.
- Migrations that change existing tables use `NOT VALID` or a concurrent
  index, so they don't block writes on a large table.

**Routes**

- `['auth:sanctum,api-key', 'tenant']` plus `permission:<module>.<ability>` on
  every route, with the ability added to the role map (ADR 0005).
- Route parameters are constrained (`whereUuid`, `whereIn`), so a malformed id
  is a 404 and never reaches a query.

**Writes**

- Lock the aggregate row `FOR UPDATE` and bump its `version`.
- Expose the version as `ETag`, and honour `If-Match` (412 on mismatch; ADR 0006).
- Dispatch the domain event once the transaction has committed.

**Lists**

- Cursor-paginate with `cursorPaginate()` ordered by `id desc`.
- Filters use explicit, validated parameters.
- Support `?fields=` via `SparseFieldset`.
- Timestamps in and out are RFC 3339 with an offset (`Rfc3339`).

**Errors**

- Domain exceptions render as `application/problem+json` with a stable `type`.
- Validation errors are 422 with field keys.

**Background work**

- Make it resumable: record each unit of work in the same transaction as its
  effect, and skip recorded units on a re-run.
- Bound each job attempt below the queue's `retry_after`.
- Use `WithoutOverlapping` for anything that must not run twice at once.

**Tests**

- Cross-tenant 404s.
- Permission and scope checks.
- Validation.
- A test that a database constraint actually rejects bad data.
- Real-process concurrency tests for anything with a lock.

## Decisions recorded here rather than in an ADR

**Cursor key.** The roadmap specifies a cursor over `(created_at, id)`. Keys are
UUID v7, which already lead with a millisecond timestamp and are generated
monotonically within a process. Ordering by `id` alone therefore gives the same
order with one index column and a smaller cursor. Laravel's cursor encoding is
already opaque base64, so no Shared helper is needed beyond `cursorPaginate()`.
`ProductListingTest` proves that paging neither skips nor repeats rows while
rows are inserted.

**SKUs.** SKUs are case-insensitive identifiers, stored upper-case and trimmed
(`Domain\Sku`), so `tee-1` and `TEE-1` cannot both exist.

**Prices.** A variant has an optional base price per currency, plus scheduled
prices that apply within half-open `[valid_from, valid_to)` windows.
`Domain\PriceList` decides which price applies at a given instant. Order
placement uses `FindVariantPrice`.

**Imports.** `POST /products/import` checks the CSV header synchronously, so a
broken file is a 422 and nothing is queued. Rows are then processed in batches
of 250 product groups, each group in its own transaction recorded in
`product_import_groups`. A worker killed at any point loses nothing and
duplicates nothing.

**Known gap until Phase 7.** Domain events are dispatched in-process. An event
fired inside an outer transaction (as during an import) is delivered even if
that transaction later rolls back. The transactional outbox closes this gap.
