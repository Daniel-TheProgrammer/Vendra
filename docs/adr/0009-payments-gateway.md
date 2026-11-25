# 0009. Payments through a gateway port, settled in one place

Date: 2026-11-25
Status: Accepted

## Context

Vendra charges customers through Stripe (test mode only). Money can move
outside our control: a customer confirms a payment in their browser, Stripe
reports it later by webhook, a network call times out after Stripe acted, and
webhooks arrive late, twice or out of order. Every cent must still land in the
ledger exactly once (ADR 0008), and tests must run without network access.

## Decision

**A port, two adapters.** `Application\Gateway\PaymentGateway` is the provider
as Payments needs it: create, retrieve, capture and cancel an intent (refunds
join it with the refunds feature). `StripeGateway` implements it over HTTP;
`FakeGateway` keeps intents in the cache with Stripe's rules (idempotency
keys, only an authorised intent can be captured, a captured one cannot be
cancelled) and Stripe's standard fee. `payments.gateway` picks one; tests and
offline work use the fake. A decline is a result, not an exception: the
intent comes back failed with the provider's code. Errors of the provider
itself are `PaymentGatewayUnavailable` (503, retryable) or `GatewayRejected`
(409, will not succeed on retry).

**Idempotent at the provider too.** Every provider call that moves money
carries an idempotency key derived from our ids (`intent:{id}`,
`capture:{id}`, `cancel:{id}`), so a retry after a timeout reaches the same
provider object instead of charging twice.

**One state machine, one door.** `PaymentIntentStatus` draws
`requires_payment → processing → succeeded | failed | cancelled`.
`PaymentSettlement::apply()` is the only code that changes an intent's status,
whoever brings the news: our own capture response, a webhook, or the order
being cancelled. Under a row lock it drops news that is stale (reported
earlier than the last report applied), repeated (the status the intent already
has) or impossible (a late `processing` after `succeeded`). So the same capture
reported by our response and by the webhook settles once.

**Capture pays the order in the same transaction.** On success the settlement
calls Orders' `RecordPayment` and raises `PaymentCaptured`, which the ledger
books with the provider's fee. If the order can no longer be paid (it was
cancelled while the customer was paying), the money is kept as
`customer_credit`: it is credited to 2200 Customer Credits, owed back until
refunded, instead of being lost or failing the webhook forever. Cancelling an
order releases its open payment at the provider, from a queued listener, so
an order is never stuck behind the provider.

**At most one payment in flight per order, and one success.** Partial unique
indexes on `payment_intents` enforce both, whatever races the application
check.

**Tenant provider accounts.** `payment_accounts` holds each tenant's provider
account id, encrypted at rest (Laravel `encrypted` cast). An inbound webhook
names the account before any tenant is known. The table is therefore a
declared platform table (outside RLS), matched through `account_lookup`, an
HMAC-SHA256 of the id under the application key. Connecting an account
decides where the money goes, so it needs `payments.configure`, which only
people hold, never API keys.

**The client secret is handed out once.** It is returned in the response
that creates the intent (`Cache-Control: no-store`) and is not stored by
Payments.

## Consequences

- Over HTTP the idempotency middleware's transaction (ADR 0007) surrounds the
  provider call. A 503 rolls the whole attempt back and a retry starts afresh.
  If the process dies after the provider captured but before we commit, the
  provider's `payment_intent.succeeded` webhook settles the intent; settling
  is idempotent, so the webhook and the response never double-book.
- The order's row is locked for the duration of a provider call when a
  payment starts. That is one order, for about a second.
- A capture the state machine refuses (money arriving for an intent we think
  is cancelled) is logged as critical and surfaces in `payments:reconcile`.
  It is never applied silently.
- The application key is also the HMAC key. Rotating it means recomputing
  `account_lookup`, as it already means re-encrypting the encrypted columns.
