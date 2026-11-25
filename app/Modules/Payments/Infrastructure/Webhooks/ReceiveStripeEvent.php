<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Webhooks;

use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\WebhookRejected;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeSignature;
use App\Modules\Payments\Infrastructure\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Infrastructure\Models\PaymentEventRecord;
use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use JsonException;

/**
 * Accepts a Stripe event: verify, store, queue, answer. Nothing is processed
 * in the request, so Stripe gets its 2xx in milliseconds whatever the
 * processing costs, and a failure while processing is retried by our queue
 * instead of by Stripe.
 *
 *   1. The tenant comes from the connected account the event names
 *      (Connect events carry "account"), never from anything we are told.
 *   2. The signature must verify against that tenant's secret (or the
 *      previous one, during a rotation). Unknown account and bad signature
 *      are the same 400, and nothing is stored.
 *   3. The event is stored verbatim; the unique gateway event id turns a
 *      redelivery into a no-op that still answers 200.
 *   4. Live-mode events are refused: Vendra only runs in test mode.
 */
final readonly class ReceiveStripeEvent
{
    public function __construct(
        private PaymentAccounts $accounts,
        private TenantContext $tenant,
        private Clock $clock,
    ) {}

    /**
     * @return bool false when this event was received before
     *
     * @throws WebhookRejected
     */
    public function __invoke(string $payload, ?string $signature): bool
    {
        $event = $this->parse($payload);
        $account = $this->accounts->findByProviderAccount($event['account']) ?? throw WebhookRejected::unverifiable();
        $now = $this->clock->now();

        if (! StripeSignature::verify($payload, $signature, $account->acceptedWebhookSecrets($now), $now->getTimestamp())) {
            throw WebhookRejected::unverifiable();
        }

        return $this->tenant->runAs($account->tenant_id, function () use ($event, $account, $now): bool {
            $id = (string) Str::uuid7();
            $stored = PaymentEventRecord::query()->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $account->tenant_id,
                'gateway' => 'stripe',
                'gateway_event_id' => $event['id'],
                'type' => $event['type'],
                'payload' => json_encode($event['raw'], JSON_THROW_ON_ERROR),
                'gateway_created_at' => CarbonImmutable::createFromTimestampUTC($event['created'])->format('Y-m-d H:i:sP'),
                'received_at' => $now->utc()->format('Y-m-d H:i:s.uP'),
            ]);

            if ($stored === 0) {
                return false;
            }

            ProcessPaymentEvent::dispatch($id, $account->tenant_id)->afterCommit();

            return true;
        });
    }

    /**
     * @return array{id: string, type: string, created: int, account: string, raw: array<string, mixed>}
     *
     * @throws WebhookRejected
     */
    private function parse(string $payload): array
    {
        try {
            $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw WebhookRejected::malformed('not JSON');
        }

        if (! is_array($event) || ($event['object'] ?? null) !== 'event') {
            throw WebhookRejected::malformed('not an event object');
        }

        $id = $event['id'] ?? null;
        $type = $event['type'] ?? null;
        $created = $event['created'] ?? null;
        $account = $event['account'] ?? null;

        if (! is_string($id) || preg_match('/^evt_\w{1,250}$/', $id) !== 1 || ! is_string($type) || strlen($type) > 100 || ! is_int($created)) {
            throw WebhookRejected::malformed('id, type or created missing');
        }

        if (! is_string($account) || $account === '') {
            // A platform event: no connected account, so no tenant to own it.
            throw WebhookRejected::malformed('no connected account');
        }

        if (($event['livemode'] ?? false) !== false) {
            throw WebhookRejected::malformed('live-mode events are not accepted');
        }

        /** @var array<string, mixed> $event */
        return ['id' => $id, 'type' => $type, 'created' => $created, 'account' => $account, 'raw' => $event];
    }
}
