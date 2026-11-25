<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\Events\PaymentDisputed;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Infrastructure\Gateway\FakeGateway;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeSignature;
use App\Modules\Payments\Infrastructure\Models\PaymentEventRecord;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Payments\Infrastructure\Webhooks\StripeEventProcessor;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * Inbound Stripe events: verified per tenant, stored once, processed into
 * the payment, the order and the ledger. Queues run synchronously here, so
 * a delivery is processed before the request returns.
 */
#[Group('database')]
final class StripeWebhookTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private const ACCOUNT = 'acct_1TestMerchant42';

    private const SECRET = 'whsec_cUrr3ntS3cr3t0000000000';

    private Tenant $tenant;

    private TenantContext $context;

    private FakeGateway $gateway;

    private string $order;

    private string $intent;

    private string $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->gateway = $this->app->make(FakeGateway::class);
        $variant = $this->asTenant(function (): string {
            $variant = ProductVariant::query()->where('product_id', Product::factory()->withVariant('LAMP-1', 2500)->create()->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 10, AdjustmentReason::Received));
            $this->app->make(PaymentAccounts::class)->connect(self::ACCOUNT);
            $this->app->make(PaymentAccounts::class)->rotateWebhookSecret(self::SECRET, 0);

            return $variant;
        });

        $this->actingAsMember($this->tenant, MembershipRole::Admin);
        $this->order = $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'buyer@example.com'],
            'lines' => [['variant_id' => $variant, 'quantity' => 4]],
        ])->json('data.id');
        $this->intent = $this->postJson('/api/v1/orders/'.$this->order.'/payment-intents')->json('data.id');
        $this->ref = $this->asTenant(fn (): string => PaymentIntent::query()->whereKey($this->intent)->valueOrFail('gateway_ref'));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function asTenant(callable $operation): mixed
    {
        return $this->context->runAs($this->tenant->id, $operation);
    }

    /**
     * A fixture event about this test's intent.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function event(string $fixture, array $envelope = []): string
    {
        $event = json_decode((string) file_get_contents(base_path('tests/Fixtures/stripe/event.'.$fixture.'.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        $event['id'] = 'evt_'.Str::random(24);

        if ($event['data']['object']['object'] === 'payment_intent') {
            $event['data']['object']['id'] = $this->ref;
            $event['data']['object']['metadata']['vendra_payment_intent_id'] = $this->intent;
        } else {
            $event['data']['object']['payment_intent'] = $this->ref;
        }

        return json_encode(array_replace($event, $envelope), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return TestResponse<Response>
     */
    private function deliver(string $payload, ?string $secret = self::SECRET, ?int $signedAt = null): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', StripeSignature::HEADER))] = StripeSignature::sign($payload, $secret, $signedAt ?? CarbonImmutable::now()->getTimestamp());
        }

        return $this->call('POST', '/api/v1/webhooks/stripe', server: $headers, content: $payload);
    }

    private function storedEvents(): int
    {
        return $this->asTenant(static fn (): int => PaymentEventRecord::query()->count());
    }

    private function intentStatus(): string
    {
        return $this->asTenant(fn (): string => PaymentIntent::query()->whereKey($this->intent)->sole()->status->value);
    }

    #[Test]
    public function a_succeeded_event_pays_the_order_and_books_the_money_with_its_fee(): void
    {
        // Stripe took the money; our capture response never arrived.
        $this->gateway->succeed(new GatewayAccount(self::ACCOUNT), $this->ref);

        $this->deliver($this->event('payment_intent.succeeded'))->assertOk()->assertExactJson(['received' => true, 'duplicate' => false]);

        $this->getJson('/api/v1/orders/'.$this->order)->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/v1/payment-intents/'.$this->intent)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.fee', '3.20');

        $event = $this->asTenant(static fn (): PaymentEventRecord => PaymentEventRecord::query()->sole());
        self::assertSame(['payment_intent.succeeded', 'applied', 1, null], [$event->type, $event->outcome, $event->attempts, $event->last_error]);
        self::assertTrue($event->gateway_created_at->equalTo(CarbonImmutable::createFromTimestampUTC(1764061260)));
        self::assertSame(1, $this->asTenant(fn (): int => JournalEntryRecord::query()->where('idempotency_key', 'payment:'.$this->intent.':captured')->count()));
    }

    #[Test]
    public function a_redelivered_event_answers_200_and_is_processed_once(): void
    {
        $this->gateway->succeed(new GatewayAccount(self::ACCOUNT), $this->ref);
        $payload = $this->event('payment_intent.succeeded');

        $this->deliver($payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->deliver($payload)->assertOk()->assertJsonPath('duplicate', true);

        self::assertSame(1, $this->storedEvents());
        self::assertSame(1, $this->asTenant(static fn (): int => JournalEntryRecord::query()->where('reference_type', 'payment')->count()));
    }

    #[Test]
    public function our_capture_and_the_webhook_report_the_same_capture_and_it_books_once(): void
    {
        $this->gateway->authorise(new GatewayAccount(self::ACCOUNT), $this->ref);
        $this->postJson('/api/v1/payment-intents/'.$this->intent.'/capture')->assertOk();

        $this->deliver($this->event('payment_intent.succeeded'))->assertOk();

        self::assertSame('ignored', $this->asTenant(static fn (): ?string => PaymentEventRecord::query()->sole()->outcome));
        self::assertSame(1, $this->asTenant(static fn (): int => JournalEntryRecord::query()->where('reference_type', 'payment')->count()));
    }

    #[Test]
    public function an_invalid_signature_is_refused_and_nothing_is_stored(): void
    {
        $payload = $this->event('payment_intent.succeeded');

        $this->deliver($payload, 'whsec_s0meb0dyElse000000000')->assertStatus(400)->assertJsonPath('type', 'urn:problem-type:webhook-rejected');
        $this->deliver($payload, null)->assertStatus(400);
        $this->deliver($payload, signedAt: CarbonImmutable::now()->subMinutes(6)->getTimestamp())->assertStatus(400);
        $this->deliver(str_replace('"amount":10000', '"amount":1', $payload))->assertOk(); // re-signed by deliver(): only the signature counts

        // Unknown account: same answer as a bad signature.
        $this->deliver($this->event('payment_intent.succeeded', ['account' => 'acct_1SomeoneElse00']))
            ->assertStatus(400)
            ->assertJsonPath('detail', 'The webhook could not be verified.');

        self::assertSame(1, $this->storedEvents());
    }

    #[Test]
    public function malformed_platform_and_live_mode_events_are_refused(): void
    {
        $this->deliver('not json')->assertStatus(400);
        $this->deliver('{"object":"charge"}')->assertStatus(400);
        $this->deliver($this->event('payment_intent.succeeded', ['account' => null]))->assertStatus(400);
        $this->deliver($this->event('payment_intent.succeeded', ['livemode' => true]))->assertStatus(400);

        self::assertSame(0, $this->storedEvents());
    }

    #[Test]
    public function an_event_older_than_what_the_intent_knows_cannot_move_it_back(): void
    {
        // Created 1764061240: the customer authorised.
        $this->deliver($this->event('payment_intent.amount_capturable_updated'))->assertOk();
        self::assertSame('processing', $this->intentStatus());

        // Created 1764061230, delivered later: an earlier failed attempt.
        $this->deliver($this->event('payment_intent.payment_failed'))->assertOk();

        $this->getJson('/api/v1/payment-intents/'.$this->intent)->assertJsonPath('data.status', 'processing');
        self::assertSame(['applied', 'ignored'], $this->asTenant(static fn (): array => PaymentEventRecord::query()->orderBy('received_at')->orderBy('id')->pluck('outcome')->all()));
        $this->getJson('/api/v1/orders/'.$this->order)->assertJsonPath('data.status', 'awaiting_payment');
    }

    #[Test]
    public function a_failed_payment_event_fails_the_intent_with_the_decline(): void
    {
        $this->deliver($this->event('payment_intent.payment_failed'))->assertOk();

        $this->getJson('/api/v1/payment-intents/'.$this->intent)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure.code', 'generic_decline');
    }

    #[Test]
    public function a_dispute_marks_the_payment_once(): void
    {
        $this->gateway->succeed(new GatewayAccount(self::ACCOUNT), $this->ref);
        $this->deliver($this->event('payment_intent.succeeded'))->assertOk();
        Event::fake([PaymentDisputed::class]);

        $this->deliver($this->event('charge.dispute.created'))->assertOk();
        $this->deliver($this->event('charge.dispute.created'))->assertOk();

        Event::assertDispatchedTimes(PaymentDisputed::class, 1);
        Event::assertDispatched(PaymentDisputed::class, fn (PaymentDisputed $event): bool => $event->paymentIntentId === $this->intent && $event->reason === 'fraudulent' && $event->amount->amount === 10000);
        self::assertNotNull($this->asTenant(fn (): ?CarbonImmutable => PaymentIntent::query()->whereKey($this->intent)->sole()->disputed_at));
    }

    #[Test]
    public function events_about_unknown_intents_or_of_other_types_are_kept_but_change_nothing(): void
    {
        $unknown = json_decode($this->event('payment_intent.succeeded'), true, flags: JSON_THROW_ON_ERROR);
        $unknown['data']['object']['id'] = 'pi_3NotOurs0000000000';
        $unknown['data']['object']['metadata'] = [];
        $this->deliver((string) json_encode($unknown))->assertOk();
        $this->deliver($this->event('payment_intent.succeeded', ['type' => 'customer.created']))->assertOk();

        self::assertSame(['unmatched', 'ignored'], $this->asTenant(static fn (): array => PaymentEventRecord::query()->orderBy('received_at')->orderBy('id')->pluck('outcome')->all()));
        self::assertSame('requires_payment', $this->intentStatus());
    }

    #[Test]
    public function an_intent_whose_creation_we_never_stored_is_found_by_our_id_in_the_metadata(): void
    {
        $this->asTenant(fn () => PaymentIntent::query()->whereKey($this->intent)->update(['gateway_ref' => null]));

        $this->deliver($this->event('payment_intent.amount_capturable_updated'))->assertOk();

        $this->getJson('/api/v1/payment-intents/'.$this->intent)->assertJsonPath('data.status', 'processing')->assertJsonPath('data.gateway_ref', $this->ref);
    }

    #[Test]
    public function processing_that_fails_keeps_the_event_with_its_error_for_the_retry(): void
    {
        $this->gateway->succeed(new GatewayAccount(self::ACCOUNT), $this->ref);
        $id = $this->asTenant(function (): string {
            $event = PaymentEventRecord::query()->create([
                'gateway' => 'stripe',
                'gateway_event_id' => 'evt_retry0000000000',
                'type' => 'payment_intent.succeeded',
                'payload' => json_decode($this->event('payment_intent.succeeded'), true, flags: JSON_THROW_ON_ERROR),
                'gateway_created_at' => CarbonImmutable::createFromTimestampUTC(1764061260),
                'received_at' => CarbonImmutable::now(),
            ]);

            return $event->id;
        });

        // Reading the fee from Stripe fails the first time.
        $this->gateway->failNextCall();
        $this->asTenant(function () use ($id): void {
            try {
                $this->app->make(StripeEventProcessor::class)($id);
                self::fail('The provider was unreachable.');
            } catch (PaymentGatewayUnavailable) {
            }

            $event = PaymentEventRecord::query()->whereKey($id)->sole();
            self::assertSame([null, 1], [$event->processed_at, $event->attempts]);
            self::assertStringContainsString('PaymentGatewayUnavailable', (string) $event->last_error);

            $this->app->make(StripeEventProcessor::class)($id);
            $event->refresh();
            self::assertSame(['applied', 2, null], [$event->outcome, $event->attempts, $event->last_error]);
        });
    }

    #[Test]
    public function a_rotated_secret_keeps_the_old_one_valid_for_the_overlap_only(): void
    {
        $next = 'whsec_n3xtS3cr3t000000000000';

        $this->putJson('/api/v1/payments/account/webhook-secret', ['secret' => $next, 'previous_valid_hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.webhook_secret_configured', true)
            ->assertJsonMissingPath('data.webhook_secret');

        $this->deliver($this->event('payment_intent.amount_capturable_updated'), self::SECRET)->assertOk();
        $this->deliver($this->event('payment_intent.amount_capturable_updated'), $next)->assertOk();

        $this->travel(25)->hours();
        $this->deliver($this->event('payment_intent.amount_capturable_updated'), self::SECRET)->assertStatus(400);
        $this->deliver($this->event('payment_intent.amount_capturable_updated'), $next)->assertOk();

        // No overlap: the old secret stops at once.
        $this->putJson('/api/v1/payments/account/webhook-secret', ['secret' => self::SECRET, 'previous_valid_hours' => 0])->assertOk()->assertJsonPath('data.previous_webhook_secret_expires_at', null);
        $this->deliver($this->event('payment_intent.amount_capturable_updated'), $next)->assertStatus(400);

        $this->putJson('/api/v1/payments/account/webhook-secret', ['secret' => 'plain-text'])->assertUnprocessable();
        $this->actingAsMember($this->tenant, MembershipRole::Staff);
        $this->putJson('/api/v1/payments/account/webhook-secret', ['secret' => $next])->assertForbidden();
    }
}
