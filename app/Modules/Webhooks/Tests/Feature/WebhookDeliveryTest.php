<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Tests\Feature;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Webhooks\Application\Security\HostResolver;
use App\Modules\Webhooks\Application\Services\WebhookSignature;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Modules\Webhooks\Infrastructure\Notifications\SubscriptionPaused;
use App\Shared\Infrastructure\Outbox\EventPublished;
use App\Shared\Infrastructure\Outbox\OutboxMessage;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixedHostResolver;
use Tests\TestCase;

/**
 * Events from the outbox to a (faked) receiver: signed, retried on the
 * schedule, dead-lettered, paused after repeated failures, redelivered.
 * Queues and the outbox run synchronously here, so a delivery is attempted
 * before the request that caused its event returns.
 */
#[Group('database')]
final class WebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const RECEIVER = 'https://hooks.example.com/vendra';

    private Tenant $tenant;

    private User $owner;

    private FixedHostResolver $dns;

    private string $subscription;

    private string $secret;

    private int $skus = 0;

    private int $receiverStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-27T09:00:00Z');
        $this->dns = new FixedHostResolver(['hooks.example.com' => ['93.184.216.34']]);
        $this->app->instance(HostResolver::class, $this->dns);
        $this->tenant = Tenant::factory()->create();
        $this->owner = $this->actingAsMember($this->tenant, MembershipRole::Owner);

        $this->subscription = $this->postJson('/api/v1/webhooks/subscriptions', [
            'url' => self::RECEIVER,
            'event_types' => ['catalog.product_created'],
        ])->assertCreated()->json('data.id');
        $this->secret = $this->getJson('/api/v1/webhooks/subscriptions/'.$this->subscription.'/secret')->json('data.secret');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function asTenant(callable $operation): mixed
    {
        return $this->app->make(TenantContext::class)->runAs($this->tenant->id, $operation);
    }

    /**
     * Creates a product: one catalog.product_created event.
     */
    private function raiseEvent(): string
    {
        $this->skus++;

        return $this->postJson('/api/v1/products', [
            'name' => 'Mug '.$this->skus,
            'status' => 'active',
            'variants' => [['sku' => 'MUG-'.$this->skus, 'prices' => [['currency' => 'USD', 'amount' => '12.00']]]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * Http::fake() stubs stack (the first match wins), so the fake is
     * registered once and answers whatever the test last asked for.
     */
    private function receiverAnswers(int $status): void
    {
        $fresh = ! isset($this->receiverStatus);
        $this->receiverStatus = $status;

        if ($fresh) {
            Http::fake(['hooks.example.com/*' => fn () => Http::response($this->receiverStatus >= 400 ? 'boom' : 'ok', $this->receiverStatus)]);
        }
    }

    private function delivery(): WebhookDelivery
    {
        return $this->asTenant(static fn (): WebhookDelivery => WebhookDelivery::query()->orderByDesc('id')->firstOrFail());
    }

    private function subscription(): WebhookSubscription
    {
        return $this->asTenant(fn (): WebhookSubscription => WebhookSubscription::query()->whereKey($this->subscription)->firstOrFail());
    }

    #[Test]
    public function an_event_reaches_the_receiver_signed_with_the_subscriptions_secret(): void
    {
        $this->receiverAnswers(200);

        $product = $this->raiseEvent();

        $event = OutboxMessage::query()->where('event_type', 'catalog.product_created')->sole();
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($event, $product): bool {
            $body = $request->body();
            $json = json_decode($body, true);

            return $request->url() === self::RECEIVER
                && $request->method() === 'POST'
                && $request->header('Content-Type') === ['application/json']
                && $request->header('Webhook-Id') === [$event->id]
                && $request->header('Webhook-Timestamp') === [(string) CarbonImmutable::now()->getTimestamp()]
                && WebhookSignature::verify($body, $request->header(WebhookSignature::HEADER)[0], $this->secret, CarbonImmutable::now()->getTimestamp())
                && ! WebhookSignature::verify($body, $request->header(WebhookSignature::HEADER)[0], 'whsec_wrong', CarbonImmutable::now()->getTimestamp())
                && is_array($json)
                && $json['id'] === $event->id
                && $json['type'] === 'catalog.product_created'
                && $json['tenant_id'] === $this->tenant->id
                && $json['data']['product_id'] === $product;
        });

        $delivery = $this->delivery();
        self::assertSame(['succeeded', 1, 200, null], [$delivery->status->value, $delivery->attempts, $delivery->response_code, $delivery->error]);
        self::assertNotNull($delivery->delivered_at);
    }

    #[Test]
    public function only_subscriptions_that_want_the_event_receive_it_and_once_however_often_it_is_published(): void
    {
        $this->receiverAnswers(200);
        $this->postJson('/api/v1/webhooks/subscriptions', ['url' => 'https://hooks.example.com/orders', 'event_types' => ['orders.order_placed']])->assertCreated();
        $this->postJson('/api/v1/webhooks/subscriptions', ['url' => 'https://hooks.example.com/all', 'event_types' => ['*']])->assertCreated();

        $this->raiseEvent();

        Http::assertSentCount(2);
        Http::assertNotSent(static fn (Request $request): bool => str_ends_with($request->url(), '/orders'));

        // The relay may publish an event again (ADR 0010): nothing more is sent.
        $message = OutboxMessage::query()->sole();
        $this->asTenant(fn () => event(new EventPublished($message->id, $this->tenant->id, $message->event_type, $message->payload, $message->occurred_at)));
        Http::assertSentCount(2);
        self::assertSame(2, $this->asTenant(static fn (): int => WebhookDelivery::query()->count()));
    }

    #[Test]
    public function a_failing_receiver_is_retried_on_the_schedule_then_the_delivery_is_dead(): void
    {
        $this->receiverAnswers(500);
        $this->raiseEvent();

        $delivery = $this->delivery();
        self::assertSame(['failed', 1, 500, 'The receiver answered 500.', 'boom'], [$delivery->status->value, $delivery->attempts, $delivery->response_code, $delivery->error, $delivery->response_excerpt]);
        self::assertTrue($delivery->next_attempt_at?->equalTo(CarbonImmutable::now()->addMinute()));

        // Not due yet.
        $this->runArtisan('webhooks:deliver-due')->assertSuccessful();
        self::assertSame(1, $this->delivery()->attempts);

        foreach ([60, 300, 1800, 7200] as $attempt => $wait) {
            $this->travel($wait)->seconds();
            $this->runArtisan('webhooks:deliver-due')->assertSuccessful();
            self::assertSame($attempt + 2, $this->delivery()->attempts);
        }

        self::assertTrue($this->delivery()->next_attempt_at?->equalTo(CarbonImmutable::now()->addHours(12)));
        $this->travel(12)->hours();
        $this->runArtisan('webhooks:deliver-due')->assertSuccessful();

        $delivery = $this->delivery();
        self::assertSame(['dead', 6, null], [$delivery->status->value, $delivery->attempts, $delivery->next_attempt_at]);
        Http::assertSentCount(6);

        $this->getJson('/api/v1/webhooks/deliveries?status=dead')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.attempts', 6);
    }

    #[Test]
    public function a_dead_delivery_is_redelivered_once_the_receiver_is_fixed(): void
    {
        config(['webhooks.retry_schedule' => []]);
        $this->receiverAnswers(503);
        $this->raiseEvent();
        $dead = $this->delivery();
        self::assertSame('dead', $dead->status->value);

        $this->receiverAnswers(200);
        $this->postJson('/api/v1/webhooks/deliveries/'.$dead->id.'/redeliver')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempts', 0);

        $this->getJson('/api/v1/webhooks/deliveries/'.$dead->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.body.type', 'catalog.product_created')
            ->assertJsonPath('data.event_id', $dead->event_id);
        Http::assertSent(static fn (Request $request): bool => $request->header('Webhook-Id') === [$dead->event_id]);
    }

    #[Test]
    public function consecutive_failures_pause_the_subscription_and_tell_its_owners(): void
    {
        config(['webhooks.pause_after_failures' => 3]);
        Notification::fake();
        $this->receiverAnswers(500);

        $this->raiseEvent();
        $this->raiseEvent();
        self::assertSame(['active', 2], [$this->subscription()->status->value, $this->subscription()->failure_count]);

        $this->raiseEvent();
        $subscription = $this->subscription();
        self::assertSame(['paused', 'failing', 3], [$subscription->status->value, $subscription->paused_reason?->value, $subscription->failure_count]);
        Notification::assertSentOnDemand(SubscriptionPaused::class, fn (SubscriptionPaused $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === [$this->owner->email] && $notification->failures === 3);

        // Paused: new events are kept, not sent.
        $this->raiseEvent();
        Http::assertSentCount(3);
        self::assertSame('pending', $this->delivery()->status->value);

        // Resumed: the waiting delivery goes out on the next sweep.
        $this->receiverAnswers(200);
        $this->patchJson('/api/v1/webhooks/subscriptions/'.$this->subscription, ['status' => 'active'])->assertOk()->assertJsonPath('data.consecutive_failures', 0);
        $this->runArtisan('webhooks:deliver-due')->assertSuccessful();
        self::assertSame('succeeded', $this->delivery()->status->value);
    }

    #[Test]
    public function a_success_clears_the_failure_streak(): void
    {
        $this->receiverAnswers(500);
        $this->raiseEvent();
        self::assertSame(1, $this->subscription()->failure_count);

        $this->receiverAnswers(200);
        $this->raiseEvent();
        self::assertSame(0, $this->subscription()->failure_count);
    }

    #[Test]
    public function a_host_re_pointed_at_an_internal_address_is_not_contacted(): void
    {
        Http::fake();
        $this->dns->point('hooks.example.com', ['10.0.0.5']);

        $this->raiseEvent();

        Http::assertNothingSent();
        $delivery = $this->delivery();
        self::assertSame('failed', $delivery->status->value);
        self::assertStringStartsWith('Blocked: The webhook host hooks.example.com resolves to a non-public address.', (string) $delivery->error);
    }

    #[Test]
    public function during_a_secret_rotation_deliveries_verify_under_either_secret(): void
    {
        $this->receiverAnswers(200);
        $this->postJson('/api/v1/webhooks/subscriptions/'.$this->subscription.'/rotate-secret', ['previous_valid_hours' => 1])->assertOk();
        $new = $this->getJson('/api/v1/webhooks/subscriptions/'.$this->subscription.'/secret')->json('data.secret');

        $this->raiseEvent();

        Http::assertSent(function (Request $request) use ($new): bool {
            $header = $request->header(WebhookSignature::HEADER)[0];
            $now = CarbonImmutable::now()->getTimestamp();

            return substr_count($header, 'v1=') === 2
                && WebhookSignature::verify($request->body(), $header, $this->secret, $now)
                && WebhookSignature::verify($request->body(), $header, $new, $now);
        });
    }

    #[Test]
    public function a_test_ping_reports_how_the_receiver_answered_without_affecting_the_subscription(): void
    {
        $this->receiverAnswers(200);
        $this->postJson('/api/v1/webhooks/subscriptions/'.$this->subscription.'/test')
            ->assertOk()
            ->assertJsonPath('data.event_type', 'webhook.test')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.response.status', 200)
            ->assertJsonPath('data.body.data.subscription_id', $this->subscription);

        $this->receiverAnswers(500);
        $this->postJson('/api/v1/webhooks/subscriptions/'.$this->subscription.'/test')
            ->assertOk()
            ->assertJsonPath('data.status', 'dead')
            ->assertJsonPath('data.response.status', 500);
        self::assertSame(0, $this->subscription()->failure_count);
    }

    #[Test]
    public function the_event_log_lists_the_tenants_own_events(): void
    {
        $this->receiverAnswers(200);
        $first = $this->raiseEvent();
        $this->raiseEvent();

        $events = $this->getJson('/api/v1/events?type=catalog.product_created')->assertOk()->assertJsonCount(2, 'data')->json('data');
        self::assertSame($first, $events[1]['data']['product_id'], 'newest first');
        self::assertNotNull($events[0]['published_at']);
        self::assertCount(1, $this->getJson('/api/v1/events?per_page=1')->json('data'));

        $this->actingAsMember(Tenant::factory()->create(), MembershipRole::Owner);
        $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/webhooks/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/webhooks/deliveries/'.$this->asTenant(static fn (): string => WebhookDelivery::query()->value('id') ?? (string) Str::uuid7()).'/redeliver')->assertNotFound();
    }
}
