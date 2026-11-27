# Webhooks

Vendra tells your systems what happened in your store by POSTing events to
URLs you subscribe. This is the contract: what is sent, how to check it came
from Vendra, and what to expect when your endpoint is down.

All endpoints need the `webhooks.manage` permission (owners and admins) or an
API key with the `webhooks:manage` scope.

## Subscribing

```http
POST /api/v1/webhooks/subscriptions
Idempotency-Key: 6b0c…

{
  "url": "https://hooks.example.com/vendra",
  "event_types": ["orders.order_placed", "payments.payment_captured"],
  "description": "Fulfilment service"
}
```

- `GET /api/v1/webhooks/event-types` lists the event types. `["*"]`
  subscribes to all of them, including ones added later.
- The URL must be **https**, on port 443 or 1024 and up, without credentials,
  and must resolve only to **public** addresses. Loopback, private networks,
  link-local (cloud metadata), carrier-grade NAT and internal names
  (`localhost`, `*.internal`, single-label names) are refused with
  `422 unsafe-webhook-url` and a `reason`. The check runs again before every
  delivery, and the request connects only to the addresses it approved.
- A tenant can have up to 25 subscriptions.

Fetch the signing secret once and store it with your receiver:

```http
GET /api/v1/webhooks/subscriptions/{id}/secret
→ {"data": {"secret": "whsec_…", "previous_secret_valid": false, …}}
```

The secret is never included in any other response.

Manage a subscription with `PATCH …/subscriptions/{id}` (`url`, `event_types`,
`description`, `status: "paused" | "active"`, with an optional `If-Match`
header), `DELETE …/subscriptions/{id}`, and
`POST …/subscriptions/{id}/test`. The test endpoint sends a signed
`webhook.test` event right away and returns how your endpoint answered.

## What is sent

```http
POST /vendra HTTP/1.1
Host: hooks.example.com
Content-Type: application/json
User-Agent: Vendra-Webhooks/1.0
Webhook-Id: 01a2c3d4-…                      (the event id)
Webhook-Timestamp: 1764234000
Webhook-Signature: t=1764234000,v1=5f2b…

{
  "id": "01a2c3d4-…",
  "type": "orders.order_placed",
  "created_at": "2026-11-27T09:00:00.123456Z",
  "tenant_id": "01a1…",
  "data": {"order_id": "01a2…", "version": 1}
}
```

`data` names what changed; fetch the current state from the API if you need
more (for example `GET /orders/{order_id}`). An event delivered late
therefore never makes you apply stale data.

## Verifying the signature

`v1` is HMAC-SHA256, in hex, of `"{t}.{raw body}"` under your secret.
Compare it in constant time, against the **raw** body exactly as received
(before any JSON parsing), and reject timestamps more than five minutes old
so a captured request cannot be replayed. While a secret is being rotated
the header carries one `v1` per secret: accept the request if any one
matches.

**PHP**

```php
function vendraWebhookIsValid(string $rawBody, string $header, string $secret, int $tolerance = 300): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

        if ($name === 't' && ctype_digit($value)) {
            $timestamp = (int) $value;
        } elseif ($name === 'v1') {
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

// $rawBody = file_get_contents('php://input');
// vendraWebhookIsValid($rawBody, $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '', getenv('VENDRA_WEBHOOK_SECRET'));
```

**Node.js**

```js
const crypto = require('node:crypto');

function vendraWebhookIsValid(rawBody, header, secret, toleranceSeconds = 300) {
  let timestamp = null;
  const signatures = [];

  for (const part of String(header).split(',')) {
    const [name, value = ''] = part.trim().split(/=(.*)/s);
    if (name === 't' && /^\d+$/.test(value)) timestamp = Number(value);
    else if (name === 'v1') signatures.push(value);
  }

  if (timestamp === null || Math.abs(Date.now() / 1000 - timestamp) > toleranceSeconds) return false;

  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest();

  return signatures.some((signature) => {
    const given = Buffer.from(signature, 'hex');
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
  });
}

// Express: use express.raw({ type: 'application/json' }) for this route so
// req.body is the raw Buffer, then:
// vendraWebhookIsValid(req.body.toString('utf8'), req.get('Webhook-Signature'), process.env.VENDRA_WEBHOOK_SECRET)
```

## Answering

Answer **2xx** within **5 seconds**. Anything else counts as a failure:
another status, a timeout, or a refused or reset connection. Redirects are
not followed. Do the work after answering (queue it). The body you return is
kept, truncated, to help you debug.

## Retries, dead letters and pausing

| Attempt | When                         |
|---------|------------------------------|
| 1       | as soon as the event happens |
| 2       | 1 minute after the first     |
| 3       | 5 minutes later              |
| 4       | 30 minutes later             |
| 5       | 2 hours later                |
| 6       | 12 hours later               |

After the sixth failure the delivery is **dead**. Nothing sends it again
until you redeliver it:

```http
GET  /api/v1/webhooks/deliveries?status=dead
POST /api/v1/webhooks/deliveries/{id}/redeliver
```

A redelivery sends the same body with the same `Webhook-Id`, and starts a
fresh retry schedule.

After **10 consecutive failed attempts** across a subscription's deliveries,
the subscription is **paused** (`paused_reason: "failing"`) and the tenant's
owners get an email. Events keep being recorded for it, but nothing is sent.
Fix your endpoint, then resume it with
`PATCH …/subscriptions/{id} {"status": "active"}`. The deliveries that were
waiting go out within a minute. Any success resets the count.

## Guarantees: what your receiver must handle

- **At least once.** The same event can arrive more than once (a retry after
  a timeout your endpoint actually processed, a redelivery). Deduplicate on
  `Webhook-Id`.
- **No ordering.** Events can arrive out of order, within a subscription and
  across them. Use `created_at`, and fetch current state rather than relying
  on the sequence.
- **Never lost.** Every event of a committed change is recorded (ADR 0010)
  and kept for 30 days in your event log:
  `GET /api/v1/events?type=…&cursor=…`.

## Rotating the secret

```http
POST /api/v1/webhooks/subscriptions/{id}/rotate-secret
{"previous_valid_hours": 24}
```

1. Rotate. For the overlap (24 hours by default, 0 to 168), every delivery
   carries two signatures, one per secret.
2. Fetch the new secret (`GET …/secret`) and deploy it to your receiver.
   Both secrets verify meanwhile.
3. After the overlap only the new secret signs.

For a leaked secret, rotate with `"previous_valid_hours": 0`: the old secret
stops signing at once.

## Delivery log

- `GET /api/v1/webhooks/deliveries` lists deliveries, filtered by `status`
  (`pending`, `succeeded`, `failed`, `dead`), `subscription_id` or
  `event_type`. Each entry shows the attempts so far, the last status code,
  the response time and any error.
- `GET /api/v1/webhooks/deliveries/{id}` shows one delivery, including the
  exact body sent.
- Finished deliveries are kept for 30 days.
