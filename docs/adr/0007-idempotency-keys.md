# 0007. Idempotency keys for mutating requests

Date: 2026-11-24
Status: Accepted

## Context

Clients retry requests: after a timeout, a dropped connection or a 502 from a
proxy. They cannot tell whether the first attempt took effect. Without a
safeguard, a retried "place order" places two orders and reserves stock twice.
HTTP gives PUT and DELETE idempotent semantics, and our PATCH writes are
guarded by `If-Match` (ADR 0006). POST has neither: every POST is a new
command.

## Decision

Mutating requests to tenant resources carry an `Idempotency-Key` header, the
same scheme Stripe and the IETF draft `draft-ietf-httpapi-idempotency-key-header`
use.

**Where it applies.** The `idempotent` route middleware is applied to every
tenant resource group that writes: catalog, members and invitations, and
every module from Orders on.

- **POST** must carry a key. Without one the request is refused with
  `400 idempotency-key-required`.
- **PUT, PATCH and DELETE** may carry one, and it is honoured when sent.

These are deliberately left out:

- Authentication flows (login, register, tokens, password reset, invitation
  acceptance) and API-key creation and rotation. Their responses contain
  freshly minted secrets. Storing those responses for replay would put
  plaintext credentials in the database, defeating the hashing that protects
  them at rest. A retry simply mints another secret, and the client discards
  the one it never received.

**Semantics.** Keys are scoped per tenant.

| Situation | Response |
|---|---|
| First request with the key | Runs. Its outcome is stored for 24 hours. |
| Same key, same request | Stored status, body, `Content-Type`, `Location` and `ETag` are replayed, with `Idempotent-Replayed: true`. Nothing runs. |
| Same key, different request | `422 idempotency-key-reused`. |
| Same key while the first is running | `409 idempotency-key-in-flight` with `Retry-After`. |

"Same request" means the same:

- method,
- path,
- query,
- principal,
- payload, compared by meaning: JSON key order and whitespace are ignored,
  list order counts, and uploaded files are compared by SHA-256.

**Which outcomes are stored.** An outcome is stored only if the request ran to
a result. The following are not stored, and the key is released so a retry
runs afresh:

- validation failures,
- conflicts with concurrent writers (409, 412),
- rate limiting (429),
- timeouts (408, 425),
- server errors (5xx).

This follows Stripe: a client that fixes its input, or retries after an outage,
must not be handed back the old failure.

**Exactly once.** The request runs, and its outcome is recorded, inside one
database transaction. The use case's own transactions become savepoints within
it. Either the effects and the stored response commit together, or neither
does. Without this, a crash between the two would leave an order placed but no
record of it, and the retry would place a second one.

A request that fails after writing is rolled back entirely. The test
`a_failed_request_leaves_no_effects_and_frees_its_key` proves it.

**Mechanics.**

- A Redis lock (`SET NX` with a TTL, via the cache lock API) serialises
  requests that share a key.
- The `idempotency_keys` row is the durable record.
- The row's unique index on `(tenant_id, key)` is the backstop if a lock ever
  expires under a slow request.
- A record left incomplete by a crashed process holds no committed effects, so
  the next attempt that obtains the lock runs the request again.

**Retention.** Keys expire after 24 hours. `idempotency:prune` runs nightly as
the ordinary application role. A dedicated row-level security policy allows
it to DELETE expired rows of any tenant. Reading other tenants' rows is still
denied.

## Consequences

- Retries are safe for every write the API exposes. Clients should generate a
  UUID per logical operation and reuse it on every retry. The test base class
  does this automatically for POST, as an SDK would.
- A write request now holds one transaction for its whole duration. Row locks
  taken by the use case are held until the response is ready, slightly longer
  than before. Requests are short, so this is acceptable. Long work belongs in
  a queued job, which is dispatched "after commit" and therefore sees only
  committed data.
- Domain events are still dispatched in-process, before the outer commit. Until
  the outbox lands (Phase 7), a listener can observe an event whose request is
  then rolled back. No listener exists yet that would act on one.
- Stored responses take space: up to 24 hours of write responses per tenant.
  This is bounded by rate limits and pruned nightly.
