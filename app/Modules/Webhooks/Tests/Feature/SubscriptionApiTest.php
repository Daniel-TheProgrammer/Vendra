<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Tests\Feature;

use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Webhooks\Application\Security\HostResolver;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixedHostResolver;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class SubscriptionApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private FixedHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = new FixedHostResolver([
            'hooks.example.com' => ['93.184.216.34'],
            'other.example.com' => ['93.184.216.35'],
            'evil.example.com' => ['169.254.169.254'],
        ]);
        $this->app->instance(HostResolver::class, $this->dns);
        $this->tenant = Tenant::factory()->create();
        $this->actingAsMember($this->tenant, MembershipRole::Admin);
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
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    private function subscribe(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/webhooks/subscriptions', [
            'url' => 'https://hooks.example.com/vendra',
            'event_types' => ['orders.order_placed', 'payments.payment_captured'],
            'description' => 'Fulfilment service',
            ...$overrides,
        ]);
    }

    #[Test]
    public function a_subscription_is_created_and_its_secret_is_only_shown_by_its_own_endpoint(): void
    {
        $response = $this->subscribe()
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.url', 'https://hooks.example.com/vendra')
            ->assertJsonPath('data.event_types', ['orders.order_placed', 'payments.payment_captured'])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.consecutive_failures', 0)
            ->assertJsonMissingPath('data.secret');
        $id = $response->json('data.id');
        $response->assertHeader('Location', route('api.v1.webhooks.subscriptions.show', ['subscription' => $id]));

        $secret = $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.previous_secret_valid', false)
            ->json('data.secret');
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/', $secret);

        $stored = $this->asTenant(static fn (): mixed => DB::table('webhook_subscriptions')->where('id', $id)->value('secret'));
        self::assertIsString($stored);
        self::assertStringNotContainsString($secret, $stored, 'stored encrypted');

        $this->getJson('/api/v1/webhooks/subscriptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    }

    #[Test]
    public function the_event_types_are_the_published_domain_events_or_everything(): void
    {
        $types = $this->getJson('/api/v1/webhooks/event-types')->assertOk()->json('data');
        self::assertContains('orders.order_placed', $types);
        self::assertContains('payments.refund_issued', $types);

        $this->subscribe(['event_types' => ['*']])->assertCreated()->assertJsonPath('data.event_types', ['*']);
        $this->subscribe(['event_types' => ['orders.order_placed', 'orders.order_teleported']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.event_types.0', 'Unknown event type(s): orders.order_teleported. GET /webhooks/event-types lists them.');
        $this->subscribe(['event_types' => []])->assertUnprocessable();
        $this->subscribe(['event_types' => ['orders.order_placed', 'orders.order_placed']])->assertUnprocessable();
    }

    #[Test]
    public function urls_that_are_not_https_or_reach_internal_networks_are_refused(): void
    {
        $this->subscribe(['url' => 'http://hooks.example.com/in'])->assertUnprocessable()->assertJsonStructure(['errors' => ['url']]);
        $this->subscribe(['url' => 'https://evil.example.com/in'])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:unsafe-webhook-url')
            ->assertJsonPath('reason', 'private_address');
        $this->subscribe(['url' => 'https://127.0.0.1/in'])->assertUnprocessable()->assertJsonPath('reason', 'private_address');
        $this->subscribe(['url' => 'https://metadata.google.internal/'])->assertUnprocessable()->assertJsonPath('reason', 'internal_name');
        $this->subscribe(['url' => 'https://nowhere.example.com/in'])->assertUnprocessable()->assertJsonPath('reason', 'unresolvable');

        $this->getJson('/api/v1/webhooks/subscriptions')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_tenant_has_a_bounded_number_of_subscriptions(): void
    {
        config(['webhooks.max_subscriptions' => 2]);

        $this->subscribe()->assertCreated();
        $this->subscribe()->assertCreated();
        $this->subscribe()->assertConflict()->assertJsonPath('type', 'urn:problem-type:webhook-subscription-limit');
    }

    #[Test]
    public function a_subscription_is_changed_paused_and_resumed_with_optimistic_locking(): void
    {
        $id = $this->subscribe()->json('data.id');

        $this->patchJson('/api/v1/webhooks/subscriptions/'.$id, ['url' => 'https://other.example.com/v2', 'description' => null], ['If-Match' => '"1"'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.url', 'https://other.example.com/v2')
            ->assertJsonPath('data.description', null);
        $this->patchJson('/api/v1/webhooks/subscriptions/'.$id, ['url' => 'https://evil.example.com/in'])->assertUnprocessable();
        $this->patchJson('/api/v1/webhooks/subscriptions/'.$id, ['event_types' => ['catalog.product_created']], ['If-Match' => '"1"'])->assertStatus(412);

        $this->patchJson('/api/v1/webhooks/subscriptions/'.$id, ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.paused_reason', 'manual');
        $this->getJson('/api/v1/webhooks/subscriptions?status=paused')->assertJsonCount(1, 'data');

        self::assertSame(1, $this->asTenant(static fn (): int => DB::table('webhook_subscriptions')->where('id', $id)->update(['failure_count' => 7])));
        $this->patchJson('/api/v1/webhooks/subscriptions/'.$id, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.paused_reason', null)
            ->assertJsonPath('data.consecutive_failures', 0);
        $this->postJson('/api/v1/webhooks/subscriptions', ['url' => 'https://hooks.example.com/x', 'event_types' => ['*'], 'status' => 'paused'])->assertUnprocessable();
    }

    #[Test]
    public function rotating_the_secret_keeps_the_old_one_for_the_overlap(): void
    {
        $id = $this->subscribe()->json('data.id');
        $first = $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')->json('data.secret');

        $this->travelTo('2026-11-27T10:00:00Z');
        $this->postJson('/api/v1/webhooks/subscriptions/'.$id.'/rotate-secret', ['previous_valid_hours' => 6])
            ->assertOk()
            ->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.previous_secret_expires_at', '2026-11-27T16:00:00+00:00');

        $secret = $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')->assertJsonPath('data.previous_secret_valid', true)->json('data.secret');
        self::assertNotSame($first, $secret);

        $this->travelTo('2026-11-27T16:00:01Z');
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')->assertJsonPath('data.previous_secret_valid', false);

        // A leaked secret is retired at once.
        $this->postJson('/api/v1/webhooks/subscriptions/'.$id.'/rotate-secret', ['previous_valid_hours' => 0])->assertOk()->assertJsonPath('data.previous_secret_expires_at', null);
        $this->postJson('/api/v1/webhooks/subscriptions/'.$id.'/rotate-secret', ['previous_valid_hours' => 500])->assertUnprocessable();
    }

    #[Test]
    public function a_deleted_subscription_is_gone(): void
    {
        $id = $this->subscribe()->json('data.id');

        $this->deleteJson('/api/v1/webhooks/subscriptions/'.$id)->assertNoContent();
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/webhooks/subscriptions/'.$id)->assertNotFound();
    }

    #[Test]
    public function only_webhook_managers_and_their_own_tenant_see_subscriptions(): void
    {
        $id = $this->subscribe()->json('data.id');

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
        $this->getJson('/api/v1/webhooks/subscriptions')->assertForbidden();
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::WebhooksManage]);
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id)->assertOk();

        $this->actingAsMember(Tenant::factory()->create(), MembershipRole::Owner);
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id)->assertNotFound();
        $this->getJson('/api/v1/webhooks/subscriptions/'.$id.'/secret')->assertNotFound();
    }
}
