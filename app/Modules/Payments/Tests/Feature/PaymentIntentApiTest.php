<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Payments\Application\Actions\CreatePaymentIntent;
use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Infrastructure\Gateway\FakeGateway;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * Collecting an order's total through the (fake) provider. Prices: 25.00 a
 * unit, no tax; the provider keeps 2.9% + 0.30.
 */
#[Group('database')]
final class PaymentIntentApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private const ACCOUNT = 'acct_1TestMerchant42';

    private Tenant $tenant;

    private TenantContext $context;

    private FakeGateway $gateway;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->gateway = $this->app->make(FakeGateway::class);
        $this->variant = $this->asTenant(function (): string {
            $variant = ProductVariant::query()->where('product_id', Product::factory()->withVariant('MUG-1', 2500)->create()->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 50, AdjustmentReason::Received));
            $this->app->make(PaymentAccounts::class)->connect(self::ACCOUNT);

            return $variant;
        });

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
        return $this->context->runAs($this->tenant->id, $operation);
    }

    private function place(int $quantity = 4): string
    {
        return $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'buyer@example.com'],
            'lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function startPayment(string $orderId): TestResponse
    {
        return $this->postJson('/api/v1/orders/'.$orderId.'/payment-intents');
    }

    private function gatewayRef(string $intentId): string
    {
        return $this->asTenant(static fn (): string => PaymentIntent::query()->whereKey($intentId)->valueOrFail('gateway_ref'));
    }

    private function account(): GatewayAccount
    {
        return new GatewayAccount(self::ACCOUNT);
    }

    /**
     * @return array{cash: int, receivable: int, fees: int, credits: int, revenue: int}
     */
    private function balances(): array
    {
        return $this->asTenant(function (): array {
            $accounts = $this->app->make(Accounts::class);
            $find = $this->app->make(FindAccountBalance::class);
            $of = static fn (string $code): int => $find($accounts->get($code, Currency::USD)->id)->balance()->amount;

            return [
                'cash' => $of(ChartOfAccounts::CASH_CLEARING),
                'receivable' => $of(ChartOfAccounts::ACCOUNTS_RECEIVABLE),
                'fees' => $of(ChartOfAccounts::PAYMENT_FEES),
                'credits' => $of(ChartOfAccounts::CUSTOMER_CREDITS),
                'revenue' => $of(ChartOfAccounts::SALES_REVENUE),
            ];
        });
    }

    #[Test]
    public function an_authorised_payment_is_captured_pays_the_order_and_books_cash_and_fee(): void
    {
        $order = $this->place();
        $started = $this->startPayment($order)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'requires_payment')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.gateway', 'fake');

        $id = $started->json('data.id');
        self::assertStringStartsWith($started->json('data.gateway_ref').'_secret_', $started->json('data.client_secret'));
        $started->assertHeader('Location', route('api.v1.payment-intents.show', ['paymentIntent' => $id]));
        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'awaiting_payment');

        // The secret is handed out once.
        $this->getJson('/api/v1/payment-intents/'.$id)->assertOk()->assertJsonMissingPath('data.client_secret');

        $this->gateway->authorise($this->account(), $this->gatewayRef($id));
        $this->postJson('/api/v1/payment-intents/'.$id.'/capture', [], ['If-Match' => '"1"'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.fee', '3.20')
            ->assertJsonPath('data.settlement', 'order');

        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'paid');
        self::assertSame(['cash' => 9680, 'receivable' => 0, 'fees' => 320, 'credits' => 0, 'revenue' => 10000], $this->balances());

        $entry = $this->asTenant(static fn (): JournalEntryRecord => JournalEntryRecord::query()->where('idempotency_key', 'payment:'.$id.':captured')->sole());
        self::assertSame(['payment', $id, 'Order 1001 paid'], [$entry->reference_type, $entry->reference_id, $entry->description]);

        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:illegal-payment-transition');
        $this->runArtisan('ledger:reconcile')->assertSuccessful();
    }

    #[Test]
    public function one_payment_at_a_time_and_only_for_an_order_that_can_be_paid(): void
    {
        $order = $this->place();
        $id = $this->startPayment($order)->json('data.id');

        $this->startPayment($order)
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:payment-intent-open')
            ->assertJsonPath('payment_intent_id', $id);

        // The database holds the line even if the check above were raced.
        $this->asTenant(function () use ($order): void {
            $this->expectException(UniqueConstraintViolationException::class);
            DB::transaction(static fn () => PaymentIntent::query()->create(['order_id' => $order, 'gateway' => 'fake', 'amount_minor' => 1, 'currency' => Currency::USD, 'status' => 'processing']));
        });
    }

    #[Test]
    public function cancelled_orders_and_unknown_orders_cannot_be_paid(): void
    {
        $order = $this->place();
        $this->postJson('/api/v1/orders/'.$order.'/cancel')->assertOk();

        $this->startPayment($order)->assertConflict()->assertJsonPath('type', 'urn:problem-type:order-not-payable');
        $this->startPayment('0195d5a8-0000-7000-8000-000000000000')->assertNotFound();
    }

    #[Test]
    public function capturing_before_the_customer_authorised_is_refused_by_the_provider(): void
    {
        $id = $this->startPayment($this->place())->json('data.id');

        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:payment-gateway-rejected')
            ->assertJsonPath('gateway_code', 'payment_intent_unexpected_state');

        $this->getJson('/api/v1/payment-intents/'.$id)->assertJsonPath('data.status', 'requires_payment');
        $this->postJson('/api/v1/payment-intents/'.$id.'/capture', [], ['If-Match' => '"7"'])->assertStatus(412);
    }

    #[Test]
    public function a_declined_card_fails_the_intent_and_the_order_waits_for_another_attempt(): void
    {
        $order = $this->place();
        $id = $this->startPayment($order)->json('data.id');
        $this->gateway->decline($this->account(), $this->gatewayRef($id), 'insufficient_funds');

        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')
            ->assertStatus(402)
            ->assertJsonPath('type', 'urn:problem-type:payment-declined')
            ->assertJsonPath('decline_code', 'insufficient_funds');

        $this->getJson('/api/v1/payment-intents/'.$id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure.code', 'insufficient_funds');
        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'awaiting_payment');
        self::assertSame(0, $this->balances()['cash']);

        $this->startPayment($order)->assertCreated();
    }

    #[Test]
    public function an_unreachable_provider_fails_the_attempt_and_a_retry_starts_afresh(): void
    {
        $order = $this->place();
        $this->gateway->failNextCall();

        $this->startPayment($order)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('type', 'urn:problem-type:payment-gateway-unavailable');

        // The request ran in the idempotency transaction (ADR 0007): nothing
        // of the failed attempt remains, and the key is free again.
        self::assertSame(0, $this->asTenant(static fn (): int => PaymentIntent::query()->count()));
        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'pending');

        $this->startPayment($order)->assertCreated();
        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'awaiting_payment');
    }

    #[Test]
    public function outside_a_request_an_unreachable_provider_leaves_a_failed_intent_that_frees_the_order(): void
    {
        $order = $this->place();
        $this->gateway->failNextCall();

        $this->asTenant(function () use ($order): void {
            try {
                $this->app->make(CreatePaymentIntent::class)($order);
                self::fail('The provider was unreachable.');
            } catch (PaymentGatewayUnavailable) {
            }

            $failed = PaymentIntent::query()->sole();
            self::assertSame(['failed', 'gateway_error'], [$failed->status->value, $failed->failure_code]);
            self::assertSame('requires_payment', $this->app->make(CreatePaymentIntent::class)($order)->intent->status->value);
        });
    }

    #[Test]
    public function cancelling_the_order_releases_the_payment_at_the_provider(): void
    {
        $order = $this->place();
        $id = $this->startPayment($order)->json('data.id');
        $this->gateway->authorise($this->account(), $this->gatewayRef($id));

        $this->postJson('/api/v1/orders/'.$order.'/cancel')->assertOk();

        $this->getJson('/api/v1/payment-intents/'.$id)->assertJsonPath('data.status', 'cancelled');
        self::assertSame('cancelled', $this->gateway->retrieve($this->account(), $this->gatewayRef($id))->status->value);
    }

    #[Test]
    public function money_captured_for_an_order_cancelled_meanwhile_is_held_as_customer_credit(): void
    {
        $order = $this->place();
        $id = $this->startPayment($order)->json('data.id');
        $this->gateway->authorise($this->account(), $this->gatewayRef($id));

        // The release job has not run yet when the capture comes in.
        Queue::fake();
        $this->postJson('/api/v1/orders/'.$order.'/cancel')->assertOk();

        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.settlement', 'customer_credit');

        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'cancelled');
        self::assertSame(['cash' => 9680, 'receivable' => 0, 'fees' => 320, 'credits' => 10000, 'revenue' => 0], $this->balances());
    }

    #[Test]
    public function the_account_is_people_only_and_stored_encrypted(): void
    {
        $this->getJson('/api/v1/payments/account')->assertOk()->assertJsonPath('data.account', 'acct_…nt42')->assertJsonPath('data.gateway', 'fake');
        $this->putJson('/api/v1/payments/account', ['account_id' => 'not-an-account'])->assertUnprocessable();
        $this->putJson('/api/v1/payments/account', ['account_id' => 'acct_1OtherMerchant'])->assertOk()->assertJsonPath('data.account', 'acct_…hant');

        $row = DB::table('payment_accounts')->where('tenant_id', $this->tenant->id)->sole();
        self::assertIsString($row->account_id);
        self::assertStringNotContainsString('acct_1OtherMerchant', $row->account_id);
        self::assertSame(hash_hmac('sha256', 'acct_1OtherMerchant', (string) config('app.key')), $row->account_lookup);

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
        $this->getJson('/api/v1/payments/account')->assertForbidden();

        $this->actingAsApiKey($this->tenant, ApiScope::cases());
        $this->putJson('/api/v1/payments/account', ['account_id' => 'acct_1Attacker000'])->assertForbidden();
    }

    #[Test]
    public function a_tenant_without_an_account_cannot_take_payments(): void
    {
        $other = Tenant::factory()->create();
        $this->actingAsMember($other, MembershipRole::Owner);

        $this->getJson('/api/v1/payments/account')->assertConflict()->assertJsonPath('type', 'urn:problem-type:payment-account-missing');
    }

    #[Test]
    public function payment_keys_take_payments_read_keys_see_them_and_other_tenants_see_nothing(): void
    {
        $order = $this->place();

        $this->actingAsApiKey($this->tenant, [ApiScope::PaymentsWrite]);
        $id = $this->startPayment($order)->assertCreated()->json('data.id');

        $this->actingAsApiKey($this->tenant, [ApiScope::OrdersRead]);
        $this->getJson('/api/v1/payment-intents/'.$id)->assertOk();
        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')->assertForbidden();

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
        $this->startPayment($order)->assertForbidden();

        $this->actingAsMember(Tenant::factory()->create(), MembershipRole::Owner);
        $this->getJson('/api/v1/payment-intents/'.$id)->assertNotFound();
        $this->postJson('/api/v1/payment-intents/'.$id.'/capture')->assertNotFound();
    }
}
