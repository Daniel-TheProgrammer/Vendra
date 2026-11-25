# Stripe fixtures

Response bodies in the shape Stripe returns for API version `2024-06-20`, the
version `StripeGateway` pins with the `Stripe-Version` header. The contract
tests replay them through `Http::fake()` to check both what we send to Stripe
and how we read what it returns.

They were written from Stripe's API reference, not captured from an account.
When the pinned version changes, or once a test account is connected,
refresh them from real test-mode traffic:

```sh
stripe payment_intents create --amount=10000 --currency=usd \
  -d capture_method=manual -d "metadata[vendra_payment_intent_id]=…" \
  --stripe-account acct_…
stripe payment_intents retrieve pi_… -d "expand[]=latest_charge.balance_transaction"
```

Strip anything identifying before committing: keep ids test-mode (`pi_3…`,
`acct_1…`), and never commit a secret key or a webhook signing secret.

Webhook event fixtures (`event.*.json`) follow the same rule, and are signed
at test time with a throwaway secret.
