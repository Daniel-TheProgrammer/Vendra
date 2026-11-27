# 0010. Transactional outbox and delivery guarantees

Date: 2026-11-26
Status: Accepted

## Context

Until now, use cases dispatched domain events on Laravel's event bus right
after their transaction returned. That has two failure modes:

- **Lost events.** If the process dies between the commit and the dispatch,
  the change is saved and its event never happens: the ledger never books
  the sale, a webhook subscriber never hears of the order.
- **Phantom events.** Inside an outer transaction (the idempotency
  middleware's, ADR 0007, or a product import's), an event is dispatched
  before the outer commit. If that transaction then rolls back, listeners
  have acted on something that never happened.

Listeners also ran inside the request, so a failing listener (the ledger,
say) failed the business change that raised the event.

Phase 7 adds outbound webhooks, where both failure modes are visible to
tenants, so events must be reliable first.

## Decision

**Record in the transaction, publish after it.** Use cases record events with
`EventRecorder::record()`, which inserts one `outbox_messages` row per event
on the same connection, inside the caller's transaction. It refuses to run
outside a transaction. The rows commit or roll back with the business change:
an event exists if and only if its cause was committed. No module code
dispatches a domain event directly; `NoSynchronousDomainEventsTest` fails the
build if one does.

**Events are rebuildable.** Each event class declares its name (`NAME`) and a
`fromStored()` constructor that rebuilds it from its own `payload()`. Modules
list their events in `moduleEvents()`, which fills the `EventRegistry`. A test
round-trips every registered event, and fails if any domain event class is
left unregistered. The payload is what webhook subscribers receive, so it is
plain JSON.

**The relay.** `OutboxRelay::relay()` takes a batch of due messages, oldest
first, `FOR UPDATE SKIP LOCKED`, and publishes each one in its own savepoint
and in its tenant:

- Any number of relays can run. SKIP LOCKED hands each message to exactly one
  of them. `OutboxRelayConcurrencyTest` drains 300 messages with two relays
  and checks that every listener call happened exactly once.
- A listener's database writes and the message's `published_at` commit
  together. A listener that only writes to the database therefore runs
  exactly once. Anything else it does (queueing a job, calling a provider)
  may be repeated if the relay dies before committing.
- A message whose listeners throw is rolled back to its savepoint and
  retried with backoff (5 s, 30 s, 2 min, 10 min, 30 min, then hourly three
  times: nine attempts over about four hours), then marked dead and logged
  as critical. Dead messages are
  kept for a person to inspect and replay. A failing message does not hold
  back the others.

**Prompt publication.** After a transaction that recorded events commits, the
recorder nudges the relay (`outbox.after_commit`): `queue` queues a
`PublishOutbox` job (production), `inline` drains in-process (tests, so a
listener's effect is visible to the next assertion), `off` leaves it to the
long-running `outbox:relay` process. That process (its own Compose service)
polls every 500 ms whatever the mode, so a lost nudge costs latency, never
an event.

**Retention.** Published messages are kept 30 days (`outbox:prune`, nightly).
They back the tenant's event log, `GET /events`.

## Guarantees, and what listeners must do

| Property     | Guarantee                                                                 |
|--------------|---------------------------------------------------------------------------|
| Durability   | An event is never lost once its transaction committed.                    |
| Atomicity    | No event exists for a rolled-back change.                                 |
| Delivery     | At least once. Database-only listeners: exactly once.                     |
| Ordering     | None. Messages are taken oldest first, but retries and concurrent relays reorder them. |
| Latency      | Milliseconds with the nudge; at most the poll interval without it.        |

Therefore every listener must be:

- **idempotent.** The ledger keys every posting; search indexing reads the
  current state; webhook deliveries are keyed by event and subscription.
- **order-tolerant.** It acts on the current state, not on the event's kind
  alone. The ledger's order listener books the sale and, if the order is
  cancelled by now, its reversal, whichever order event arrives first.

## Outbound webhooks

Webhook delivery is the outbox's main consumer, and adds its own guarantees
(the contract for subscribers is in `docs/webhooks.md`):

- **Fan-out with the publication.** For each message, the relay also
  dispatches an `EventPublished` envelope, which carries the message id. The
  Webhooks module creates one delivery per interested subscription in the
  relay's savepoint, so deliveries commit with the published mark. A unique
  (subscription, event) pair makes a republished event create nothing new.
- **At least once per subscription; no ordering.** Each delivery is
  attempted, then retried at 1 min, 5 min, 30 min, 2 h and 12 h, then marked
  dead until a redelivery. Attempts claim their delivery with a lease, so the
  queued job and the minute sweeper (`webhooks:deliver-due`) never send it
  concurrently. A receiver can still see a delivery twice: if it answered
  after our timeout, or after a redelivery. It deduplicates on
  `Webhook-Id`, the event id.
- **Signed.** Every request is signed: HMAC-SHA256 over the timestamp and
  the body, with both secrets during a rotation.
- **Never to Vendra's own network.** The SSRF guard checks the URL on
  subscribe and again on every attempt, and the request connects only to an
  address it approved, so DNS rebinding cannot redirect it.
- **Failing receivers are paused.** Ten consecutive failed attempts pause
  the subscription and email the tenant's owners. Its events keep
  accumulating as pending deliveries until it is resumed.

## Consequences

- A failing listener no longer fails the request that raised the event. The
  ledger can briefly lag a sale (milliseconds normally, the retry backoff
  after a failure). `ledger:reconcile` and `payments:reconcile` still compare
  totals, not timing.
- Tests that injected a failure through a throwing listener now inject it
  while the event is recorded (`Tests\Support\FailsWhenRecording`), which is
  inside the business transaction.
- `outbox_messages` is a declared platform table. The relay reads all tenants
  from one queue, and tenant-facing reads filter by `tenant_id` explicitly.
- One more process to run: `outbox-relay`. Running more than one is safe.
