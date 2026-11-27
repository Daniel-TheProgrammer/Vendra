# Payments

How Vendra charges customers, hears back from the payment provider, and keeps
every cent in the ledger. The decisions behind it are in
[ADR 0009](adr/0009-payments-gateway.md); the bookings in
[ADR 0008](adr/0008-double-entry-ledger.md).

Vendra runs **Stripe in test mode only**. A live key (`sk_live_…`) is refused
when the gateway is built, and live-mode webhook events are rejected.

## The flow

```
client                     Vendra                              Stripe
  │ POST /orders/{id}/payment-intents                            │
  │──────────────────────────►│ order → awaiting_payment          │
  │                           │ create intent (manual capture) ──►│
  │◄── 201 {client_secret} ───│                                   │
  │ confirm in the browser with Stripe.js ──────────────────────►│ authorised
  │                           │◄── payment_intent.amount_capturable_updated
  │ POST /payment-intents/{id}/capture                            │
  │──────────────────────────►│ capture ────────────────────────►│
  │◄── 200 succeeded ─────────│ order → paid, ledger booked       │
  │                           │◄── payment_intent.succeeded (no-op: already settled)
```

The capture response and the `payment_intent.succeeded` webhook report the same
capture. Whichever arrives first settles the payment; the second changes
nothing. If our process dies after Stripe captured but before we committed, the
webhook still settles it.

## Payment intent states

```
requires_payment ──► processing ──► succeeded
       │                 │
       ├──► failed ◄─────┤
       └──► cancelled ◄──┘
```

| Status             | Meaning                                                        |
|--------------------|----------------------------------------------------------------|
| `requires_payment` | Created; the customer has not authorised yet.                  |
| `processing`       | Authorised: the money is held on the card, not yet taken.      |
| `succeeded`        | Captured. The order is paid (or, see below, credited).         |
| `failed`           | Declined. Final: the order waits for a new intent.             |
| `cancelled`        | Released before capture, e.g. because the order was cancelled. |

An order has at most one intent in flight and at most one that succeeded; the
database enforces both.

## Endpoints

| Method and path                         | Permission           | Notes                                                       |
|-----------------------------------------|----------------------|-------------------------------------------------------------|
| `POST /orders/{id}/payment-intents`     | `payments.manage`    | `Idempotency-Key` required. Returns `client_secret` once.  |
| `GET /payment-intents/{id}`             | `orders.view`        | ETag is the version.                                        |
| `POST /payment-intents/{id}/capture`    | `payments.manage`    | Optional `If-Match`. 402 `payment-declined` on a decline.  |
| `GET`, `PUT /payments/account`          | `payments.configure` | `{"account_id": "acct_…"}`. People only, never API keys.   |
| `POST /payment-intents/{id}/refunds`    | `payments.manage`    | `{"amount": "25.00", "reason": "…"}`; no amount: the rest. |
| `GET /refunds`                          | `orders.view`        | `?payment_intent_id=&status=&per_page=&cursor=`             |
| `GET /refunds/{id}`                     | `orders.view`        |                                                             |
| `PUT /payments/account/webhook-secret`  | `payments.configure` | `{"secret": "whsec_…", "previous_valid_hours": 24}`        |
| `POST /webhooks/stripe`                 | signature            | Called by Stripe, not by clients.                           |

Errors that are specific to payments:

| Status | Type                          | When                                                         |
|--------|-------------------------------|--------------------------------------------------------------|
| 402    | `payment-declined`            | The card was declined at capture. The intent is now failed. |
| 409    | `payment-intent-open`         | The order already has a payment in flight.                   |
| 409    | `order-not-payable`           | The order is not pending or awaiting payment, or is free.    |
| 409    | `payment-account-missing`     | The tenant has not connected an account.                     |
| 409    | `payment-gateway-rejected`    | Stripe refused the request (e.g. capture before authorise).  |
| 409    | `illegal-payment-transition`  | E.g. capturing an intent that already succeeded.             |
| 409    | `refund-not-allowed`          | Not captured, or more than is left (`refundable` says how much). |
| 503    | `payment-gateway-unavailable` | Stripe unreachable or failing. Retry with the same key.      |

## Money in the ledger

| Event               | Entry                                                                                  |
|---------------------|----------------------------------------------------------------------------------------|
| Payment captured    | Dr Cash / Stripe Clearing (amount − fee), Dr Payment Fees (fee) / Cr Accounts Receivable |
| Refund succeeded    | Dr Refunds (refund − tax share), Dr Tax Payable (tax share) / Cr Cash / Stripe Clearing  |

The sale stays booked; Refunds is its contra-revenue account. A refund's tax
share is allocated cumulatively, `floor(refunded so far × tax ÷ total)` minus
the same before it, so any sequence of partial refunds returns exactly the
order's tax (a property test checks 500 random sequences). Stripe keeps its fee
on a refund, so Payment Fees is unchanged.

When an order is cancelled while its customer is paying, cancelling releases
the payment at Stripe. If the money was nonetheless captured first, the payment
settles as `customer_credit`: it is credited to Customer Credits (owed back to
the customer) instead of the cancelled order's receivable, and logged.

## Refunds

```
pending ──► succeeded
   └──────► failed
```

`POST /payment-intents/{id}/refunds` refunds part or all of a succeeded payment.
What is left to refund is computed under the payment's row lock, counting
pending refunds, and a database trigger refuses any refund that would take the
total past the amount paid. The Stripe call is keyed on our refund id.

- **Succeeded** (most card refunds, at once): `RefundIssued` is raised and the
  ledger books it. The refund that returns the last of an order's payment moves
  the order to `refunded`, which also gives back its stock if it never shipped.
- **Pending** (some payment methods): nothing is booked until Stripe reports the
  refund succeeded (`charge.refunded` / `charge.refund.updated`).
- **Failed**: its amount can be refunded again; nothing is booked.
- **Made in the Stripe dashboard**: the `charge.refunded` webhook syncs the
  payment's refunds with Stripe's list, records the unknown one (reason
  "Refunded at the payment provider", no `requested_by`), and books it like any
  other.
- **Held as customer credit**: refunding it books Dr Customer Credits / Cr Cash,
  clearing what was owed back.

## Inbound webhooks

`POST /api/v1/webhooks/stripe` takes Stripe Connect events for every tenant:

1. **Tenant.** The tenant is the one whose connected account the event names
   (`account`), matched through an HMAC of the account id. Nothing the caller
   says is trusted for this.
2. **Signature.** `Stripe-Signature` must verify against that tenant's
   signing secret (or the previous one during a rotation), with a timestamp
   within five minutes. Unknown account and bad signature both answer `400
   webhook-rejected`, and nothing is stored.
3. **Store once.** The raw event is stored in `payment_events` under a
   unique Stripe event id. A redelivery answers `200` with
   `"duplicate": true` and is not processed again.
4. **Queue and answer.** Processing runs on the queue, so Stripe gets its
   `200` immediately, whatever processing costs.

Processing (`StripeEventProcessor`):

| Event                                        | Effect                                     |
|----------------------------------------------|--------------------------------------------|
| `payment_intent.amount_capturable_updated`   | intent → `processing`                      |
| `payment_intent.processing`                  | intent → `processing`                      |
| `payment_intent.succeeded`                   | intent → `succeeded`: order paid, booked   |
| `payment_intent.payment_failed`              | intent → `failed`, with the decline code   |
| `payment_intent.canceled`                    | intent → `cancelled`                       |
| `charge.dispute.created`                     | intent marked disputed, `PaymentDisputed`  |
| `charge.refunded`, `charge.refund.updated`   | refunds synced with Stripe's list, booked  |
| anything else                                | stored, outcome `ignored`                  |

**Out of order.** Each event carries Stripe's `created` time. An intent
remembers the newest report applied to it; an older event arriving later is
ignored (outcome `ignored`) and can never move the intent back.

**Outcomes.** Every stored event ends `applied`, `ignored` (nothing new) or
`unmatched` (about an intent Vendra does not know). An event whose processing
fails keeps its `last_error` and is retried with backoff (8 attempts over
about three hours); one that exhausts its retries is logged as critical.

## Rotating the webhook secret

Stripe signs with one secret per endpoint. To rotate without dropping events:

1. In the Stripe dashboard, roll the endpoint's signing secret, choosing how
   long the old one stays valid (Stripe signs with both meanwhile).
2. Store the new secret, keeping the old one for the same overlap:

   ```http
   PUT /api/v1/payments/account/webhook-secret
   {"secret": "whsec_new…", "previous_valid_hours": 24}
   ```

3. Until the overlap ends, events signed with either secret verify. After it,
   only the new one does. `previous_valid_hours: 0` ends the old secret at
   once, for a secret that leaked.

`StripeWebhookTest::a_rotated_secret_keeps_the_old_one_valid_for_the_overlap_only`
covers this.

## Reconciliation

`php artisan payments:reconcile [--since=2026-11-01]` (daily at 02:45, after
`ledger:reconcile`) compares, for each tenant and since the given instant
(default: seven days):

- every **charge** in Stripe's balance has a succeeded intent here with the
  same fee (`unsettled_charge`, `fee_mismatch`);
- every intent settled here has a charge at Stripe (`missing_charge`);
- per currency, Stripe's **net** movement equals what the ledger booked on
  Cash / Stripe Clearing from payments (`clearing_mismatch`).

Every discrepancy is logged at critical level and fails the command. It never
fixes anything: the usual remedies are replaying a stored event
(`ProcessPaymentEvent::dispatch($eventId, $tenantId)`) or a manual ledger
entry with a reason.

## Working locally

Without network access, keep `PAYMENTS_GATEWAY=fake`. The fake gateway follows
Stripe's rules and fee; drive the customer's side from `php artisan tinker`:

```php
$fake = app(App\Modules\Payments\Infrastructure\Gateway\FakeGateway::class);
$fake->authorise(new App\Modules\Payments\Application\Gateway\GatewayAccount('acct_…'), 'pi_fake_…');
```

Against Stripe test mode:

1. Set `PAYMENTS_GATEWAY=stripe` and `STRIPE_SECRET=sk_test_…` in `.env`.
2. Connect a tenant to a test connected account:
   `PUT /api/v1/payments/account {"account_id": "acct_…"}`.
3. Start the webhook forwarder: `docker compose --profile stripe up -d stripe-cli`.
   `docker compose logs stripe-cli` prints its signing secret (`whsec_…`).
4. Store that secret for the tenant: `PUT /api/v1/payments/account/webhook-secret`.
5. Create and capture a payment through the API, confirming it with a
   [test card](https://docs.stripe.com/testing) (`4242 4242 4242 4242`
   succeeds; `4000 0000 0000 9995` is declined for insufficient funds).
6. Check it landed: `GET /api/v1/ledger/trial-balance` and
   `php artisan payments:reconcile --since=today`.

## Not covered yet

- **Dispute outcomes.** A dispute is recorded but not booked; the amount
  Stripe withholds and the outcome (won or lost) are booked when dispute
  resolution is built.
- **Dispute webhooks carry no outcome yet.** `payments.payment_disputed`
  reaches subscribers like every event (`docs/webhooks.md`), but the
  outcome of a dispute is not an event until dispute resolution is built.
