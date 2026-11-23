# 0006. Optimistic concurrency for catalog writes

Date: 2026-11-23
Status: Accepted

## Context

Merchants edit products from several places at once: the dashboard, bulk
imports and integrations holding API keys. Without protection, two clients that
read the same product and both save overwrite each other silently (the lost
update problem). Prices are the worst case: a stale price edit can undo a price
change another person just made.

Locking a product while a person edits it in a UI is not an option for a
stateless API. The API needs a way for a client to say "apply this only if the
product is still what I last saw".

## Decision

- The product is the unit of consistency. Its variants and prices are
  changed only through it, and every change to the product, a variant or a
  price increments `products.version` in the same transaction.
- Each write first takes a row lock on the product (`SELECT ... FOR UPDATE`).
  That serialises writers to one product, makes the version check and bump
  atomic, and costs nothing for writers to other products.
- Every product response carries `ETag: "<version>"`. A client that sends it
  back in `If-Match` gets its write applied only if the version still
  matches. Otherwise the API answers `412 precondition-failed` with
  `current_version`. A malformed `If-Match` is a `400`.
- `If-Match` is optional. Without it, writes are unconditional (last write
  wins), which keeps simple integrations simple.
- A write that changes nothing (same name, same price) does not bump the
  version, so it never invalidates the ETags other clients hold.
- Archiving is idempotent: archiving an archived product succeeds even with an
  old `If-Match`, so a retried request cannot turn into a spurious `412`.

## Alternatives considered

- **`updated_at` as the version.** Timestamps collide within the clock's
  resolution and depend on clock agreement between servers.
- **Requiring `If-Match` (`428 Precondition Required`).** Safer by default, but
  it breaks naive clients and bulk tools. This can be revisited per endpoint if
  lost updates show up in practice.
- **Per-variant versions.** Finer grained, but clients would track many ETags
  and a product-level response could not describe its own state with one.

## Consequences

- Concurrent writes to the same product queue on the row lock; writes are
  short, so the wait is negligible at catalog write rates.
- Events (`catalog.product_*`) carry the new version, so consumers can discard
  out-of-order deliveries.
- The same pattern (aggregate version, row lock, ETag and If-Match) is the
  template for orders and other aggregates edited by several clients.
