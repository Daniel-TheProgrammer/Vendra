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
