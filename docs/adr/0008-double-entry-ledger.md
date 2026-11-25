# 0008. Double-entry ledger, materialised balances and reversals

Date: 2026-11-24
Status: Accepted

## Context

Every cent that moves through Vendra (sales, tax, payments, fees, refunds)
must be accounted for in a form an accountant or auditor accepts. It must be
impossible to make money appear, disappear or change after the fact, even
through a bug or a careless SQL session. Balances are read on every dashboard
and report, so they must be cheap. They must also be provably equal to the
underlying record.

## Decision

**Double entry.** Money is recorded as journal entries. Each entry has at
least one debit and one credit line, in one currency, and debits equal credits.

**Accounts.**

- Accounts come from a fixed chart (`Domain\ChartOfAccounts`): asset,
  liability, equity, revenue and expense. Each type has a normal balance side.
- An account exists per currency. Amounts in different currencies are never
  added together, and there is no implicit FX.
- A new tenant's chart opens in `LEDGER_DEFAULT_CURRENCY`. Other currencies
  open on their first posting.

**The domain enforces the rules first.**

- `JournalEntry::draft()->debit()->credit()->post()` is immutable at every step.
- `post()` refuses an entry that:
  - is unbalanced,
  - mixes currencies,
  - is one-sided.
- Only `post()` produces a `PostedJournalEntry`, so code holding one knows the
  rules were checked.
- `split()` allocates by ratio through `Money::allocate`, so no minor unit is
  ever lost or invented.

**The database enforces them again**, independently of the application:

- A check constraint makes each line exactly one of debit or credit, positive.
- Composite foreign keys `(tenant_id, entry_id, currency)` and
  `(tenant_id, account_id, currency)` make a line's currency equal its entry's
  and its account's.
- A deferred constraint trigger checks at commit that every inserted entry has
  at least two lines and balances. Lines may be written one by one within the
  transaction.
- Triggers reject any UPDATE or DELETE of entries or lines.
- Every table is under row-level security.

**Immutability and reversal.** A posted entry never changes. A correction is a
new entry with every line on the opposite side, created by
`ReverseJournalEntry`:

- The reversal is linked to the original by `reverses_entry_id`, which is
  unique, so an entry is reversed at most once.
- A reversal can itself be reversed, which books the original lines again.
- The record shows what was booked, when, and when and why it was undone.

**Exactly once.** Every entry carries an `idempotency_key`, unique per tenant,
such as `order:{id}:placed` or `reversal:{entry}`. Posting an existing key
returns the entry already posted. Listeners, retried jobs and replayed events
therefore cannot double-book. This holds under concurrency: in the
eight-process test, every worker posts the same key and one entry results.

**Postings from business events** (`Application\Postings`). Listeners book
domain events, keyed so a redelivered event books once:

| Event | Key | Entry |
|---|---|---|
| `OrderPlaced` | `order:{id}:placed` | Dr Accounts Receivable (total) / Cr Sales Revenue (subtotal), Cr Tax Payable (tax) |
| `OrderCancelled` | `reversal:{placed entry}` | Reverses the placed entry |
| `PaymentCaptured` | `payment:{intent}:captured` | Dr Cash / Stripe Clearing (amount − fee), Dr Payment Fees (fee) / Cr Accounts Receivable (amount); Cr Customer Credits instead when the order was cancelled while the customer paid |

*Amended 2026-11-25 (ADR 0009).* Money is booked from the Payments events, not
from the order's status: `OrderPaid` and `OrderRefunded` book nothing. Only the
payment knows the fee the provider kept, and what was actually refunded.
Today listeners run in-process, inside the request's transaction (ADR 0007). If
a posting fails, the business change that caused it rolls back too. With the
outbox (Phase 7), they will run from it with retries, which the keys already
make safe.

**Materialised balances.** `account_balances` holds running debit and credit
totals per account. They are updated by `PostJournalEntry` in the posting's
own transaction:

- The rows are locked `FOR UPDATE` in account-id order, so concurrent postings
  to shared accounts queue instead of losing updates or deadlocking.
- `LedgerConcurrencyTest` runs 100 postings from 8 processes. With the lock
  removed, it fails with a balance off by 12,425 minor units.

Current balances are a single-row read. Balances as of a past instant are
summed from the lines.

**Reconciliation.** `ledger:reconcile` runs nightly and compares every
account's stored totals with the sum of its lines, for every tenant:

- Drift is logged at critical level and the command fails.
- It never "repairs" a balance: drift means something wrote around the ledger,
  and that is investigated, not papered over.

## Consequences

- Fixing a mistake is always an additional entry. Reports must show reversals
  rather than hide them. This is a feature for auditors.
- Posting serialises on the balance rows of the accounts it touches. A tenant's
  high-traffic accounts, such as Accounts Receivable, become a hotspot under
  heavy concurrent placement. If that ever limits throughput, the remedy is
  sharded balance rows summed on read. The ledger itself would not change.
- The deferred balance trigger reads lines under the tenant's row-level
  security. A transaction that commits after its tenant context was cleared
  would fail the check. This fails closed: nothing unbalanced or foreign is
  ever accepted.
- Under `RefreshDatabase` the outer transaction never commits, so deferred
  checks do not run in ordinary feature tests. Tests that exercise them force
  them with `SET CONSTRAINTS ALL IMMEDIATE`. The concurrency test commits for
  real.
