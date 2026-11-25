<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Domain\StockLevel;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Orders\Domain\Pricing\TaxStrategy;
use App\Modules\Payments\Application\Actions\SyncRefunds;
use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Infrastructure\Gateway\FakeGateway;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeSignature;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * Order → payment → capture → refund, through the API, the fake provider
 * and the ledger. Prices: 25.00 a unit; the provider keeps 2.9% + 0.30.
 */
#[Group('database')]
final class RefundFlowTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private const ACCOUNT = 'acct_1TestMerchant42';

    private const SECRET = 'whsec_r3fundS3cr3t00000000000';

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
            $variant = ProductVariant::query()->where('product_id', Product::factory()->withVariant('KETTLE-1', 2500)->create()->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 20, AdjustmentReason::Received));
            $this->app->make(PaymentAccounts::class)->connect(self::ACCOUNT);
            $this->app->make(PaymentAccounts::class)->rotateWebhookSecret(self::SECRET, 0);

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

    private function account(): GatewayAccount
    {
        return new GatewayAccount(self::ACCOUNT);
    }

    private function withTax(int $basisPoints): void
    {
        config(['orders.tax_rate_basis_points' => $basisPoints]);
        $this->app->forgetInstance(TaxStrategy::class);
    }

    /**
     * Places an order for $quantity units and pays it in full.
     *
     * @return array{order: string, intent: string, ref: string}
     */
    private function paidOrder(int $quantity = 4): array
    {
        $order = $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'buyer@example.com'],
            'lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
        $intent = $this->postJson('/api/v1/orders/'.$order.'/payment-intents')->assertCreated()->json('data.id');
        $ref = $this->asTenant(static fn (): string => PaymentIntent::query()->whereKey($intent)->valueOrFail('gateway_ref'));
        $this->gateway->authorise($this->account(), $ref);
        $this->postJson('/api/v1/payment-intents/'.$intent.'/capture')->assertOk();

        return ['order' => $order, 'intent' => $intent, 'ref' => $ref];
    }

    /**
     * @return array{cash: int, receivable: int, tax: int, revenue: int, fees: int, refunds: int, credits: int}
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
                'tax' => $of(ChartOfAccounts::TAX_PAYABLE),
                'revenue' => $of(ChartOfAccounts::SALES_REVENUE),
                'fees' => $of(ChartOfAccounts::PAYMENT_FEES),
                'refunds' => $of(ChartOfAccounts::REFUNDS),
                'credits' => $of(ChartOfAccounts::CUSTOMER_CREDITS),
            ];
        });
    }

    private function stock(): StockLevel
    {
        return $this->asTenant(fn (): StockLevel => $this->app->make(FindStock::class)($this->variant));
    }

    private function trialBalance(): int
    {
        return (int) $this->asTenant(static fn (): mixed => DB::table('journal_lines')->sum(DB::raw('debit - credit')));
    }

    /**
     * The roadmap's integration test: the whole flow leaves Accounts
     * Receivable at zero and the Refunds expense equal to the refund.
     */
    #[Test]
    public function order_payment_capture_and_full_refund_leave_receivable_at_zero_and_refunds_equal_to_the_refund(): void
    {
        $paid = $this->paidOrder();
        self::assertSame(4, $this->stock()->reserved);

        $refund = $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['reason' => 'Changed their mind'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.reason', 'Changed their mind');
        $refund->assertHeader('Location', route('api.v1.refunds.show', ['refund' => $refund->json('data.id')]));

        self::assertSame(
            ['cash' => -320, 'receivable' => 0, 'tax' => 0, 'revenue' => 10000, 'fees' => 320, 'refunds' => 10000, 'credits' => 0],
            $this->balances(),
        );
        $this->getJson('/api/v1/orders/'.$paid['order'])->assertJsonPath('data.status', 'refunded');
        self::assertSame(0, $this->stock()->reserved, 'a refunded order that never shipped gives its stock back');
        self::assertSame(0, $this->trialBalance());

        $entry = $this->asTenant(static fn (): JournalEntryRecord => JournalEntryRecord::query()->where('reference_type', 'refund')->sole());
        self::assertSame(['refund:'.$refund->json('data.id').':succeeded', 'Order 1001 refunded'], [$entry->idempotency_key, $entry->description]);

        $this->runArtisan('ledger:reconcile')->assertSuccessful();
        $this->runArtisan('payments:reconcile')->assertSuccessful();
    }

    #[Test]
    public function partial_refunds_return_tax_in_proportion_and_the_last_one_refunds_the_order(): void
    {
        $this->withTax(2000);
        $paid = $this->paidOrder(); // 100.00 + 20.00 tax

        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '50.00'])->assertCreated();
        // 50 of 120: 8.33 of tax back, 41.67 against the sale.
        self::assertSame([4167, 1167], [$this->balances()['refunds'], $this->balances()['tax']]);
        $this->getJson('/api/v1/orders/'.$paid['order'])->assertJsonPath('data.status', 'paid');

        // Without an amount: whatever is left.
        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds')->assertCreated()->assertJsonPath('data.amount', '70.00');

        $balances = $this->balances();
        self::assertSame([10000, 0, 0], [$balances['refunds'], $balances['tax'], $balances['receivable']]);
        $this->getJson('/api/v1/orders/'.$paid['order'])->assertJsonPath('data.status', 'refunded');
        self::assertSame(0, $this->trialBalance());
        $this->runArtisan('payments:reconcile')->assertSuccessful();
    }

    #[Test]
    public function no_more_can_be_refunded_than_was_paid_and_only_once_it_was_paid(): void
    {
        $paid = $this->paidOrder();
        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '60.00'])->assertCreated();

        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '40.01'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:refund-not-allowed')
            ->assertJsonPath('refundable', '40.00');
        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '0'])->assertConflict();
        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '1.234'])->assertUnprocessable()->assertJsonStructure(['errors' => ['amount']]);
        $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '-5'])->assertUnprocessable();

        $order = $this->postJson('/api/v1/orders', ['currency' => 'USD', 'customer' => ['email' => 'b@example.com'], 'lines' => [['variant_id' => $this->variant, 'quantity' => 1]]])->json('data.id');
        $unpaid = $this->postJson('/api/v1/orders/'.$order.'/payment-intents')->json('data.id');
        $this->postJson('/api/v1/payment-intents/'.$unpaid.'/refunds')
            ->assertConflict()
            ->assertJsonPath('detail', sprintf('Payment %s is requires_payment; only a succeeded payment can be refunded.', $unpaid));

        // The database holds the line too.
        $this->asTenant(function () use ($paid): void {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessage('exceed the amount paid');
            DB::transaction(static fn () => Refund::query()->create([
                'payment_intent_id' => $paid['intent'], 'gateway' => 'fake', 'amount_minor' => 4001,
                'currency' => Currency::USD, 'status' => RefundStatus::Pending,
            ]));
        });
    }

    #[Test]
    public function a_pending_refund_is_booked_when_the_provider_reports_it_succeeded(): void
    {
        $paid = $this->paidOrder();
        $this->gateway->holdRefunds();

        $id = $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds', ['amount' => '25.00'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');
        self::assertSame(0, $this->balances()['refunds'], 'nothing is booked until the money has left');

        $ref = $this->asTenant(static fn (): string => Refund::query()->whereKey($id)->valueOrFail('gateway_ref'));
        $this->gateway->settleRefund($this->account(), $paid['ref'], $ref, RefundStatus::Succeeded);
        $this->deliverChargeRefunded($paid['ref'])->assertOk();

        $this->getJson('/api/v1/refunds/'.$id)->assertJsonPath('data.status', 'succeeded');
        self::assertSame(2500, $this->balances()['refunds']);
    }

    #[Test]
    public function a_refund_that_fails_at_the_provider_frees_its_amount_and_books_nothing(): void
    {
        $paid = $this->paidOrder();
        $this->gateway->holdRefunds();
        $id = $this->postJson('/api/v1/payment-intents/'.$paid['intent'].'/refunds')->json('data.id');
        $ref = $this->asTenant(static fn (): string => Refund::query()->whereKey($id)->valueOrFail('gateway_ref'));

        $this->gateway->settleRefund($this->account(), $paid['ref'], $ref, RefundStatus::Failed);
        $this->deliverChargeRefunded($paid['ref'])->assertOk();

        $this->getJson('/api/v1/refunds/'.$id)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', 'expired_or_canceled_card');
        self::assertSame(0, $this->balances()['refunds']);
        $this->getJson('/api/v1/orders/'.$paid['order'])->assertJsonPath('data.status', 'paid');
    }

    #[Test]
    public function a_refund_made_in_the_providers_dashboard_is_recorded_and_booked(): void
    {
        $paid = $this->paidOrder();
        $this->gateway->refundAtProvider($this->account(), $paid['ref'], 3000);

        $this->deliverChargeRefunded($paid['ref'])->assertOk();
        // Delivered again, or synced again: still one refund.
        $this->asTenant(fn (): int => $this->app->make(SyncRefunds::class)(PaymentIntent::query()->whereKey($paid['intent'])->sole()));

        $refunds = $this->getJson('/api/v1/refunds?payment_intent_id='.$paid['intent'])->assertOk()->json('data');
        self::assertCount(1, $refunds);
        self::assertSame(['succeeded', '30.00', SyncRefunds::EXTERNAL_REASON, null], [$refunds[0]['status'], $refunds[0]['amount'], $refunds[0]['reason'], $refunds[0]['requested_by']]);
        self::assertSame(3000, $this->balances()['refunds']);
    }

    #[Test]
    public function refunding_money_held_as_customer_credit_settles_the_credit(): void
    {
        $order = $this->postJson('/api/v1/orders', ['currency' => 'USD', 'customer' => ['email' => 'b@example.com'], 'lines' => [['variant_id' => $this->variant, 'quantity' => 4]]])->json('data.id');
        $intent = $this->postJson('/api/v1/orders/'.$order.'/payment-intents')->json('data.id');
        $this->gateway->authorise($this->account(), $this->asTenant(static fn (): string => PaymentIntent::query()->whereKey($intent)->valueOrFail('gateway_ref')));
        Queue::fake();
        $this->postJson('/api/v1/orders/'.$order.'/cancel')->assertOk();
        $this->postJson('/api/v1/payment-intents/'.$intent.'/capture')->assertJsonPath('data.settlement', 'customer_credit');

        $this->postJson('/api/v1/payment-intents/'.$intent.'/refunds')->assertCreated();

        self::assertSame(['cash' => -320, 'revenue' => 0, 'refunds' => 0, 'credits' => 0], array_intersect_key($this->balances(), array_flip(['credits', 'refunds', 'revenue', 'cash'])));
        $this->getJson('/api/v1/orders/'.$order)->assertJsonPath('data.status', 'cancelled');
    }

    #[Test]
    public function refunds_are_listed_filtered_and_kept_to_their_tenant_and_permissions(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder(1);
        $this->postJson('/api/v1/payment-intents/'.$first['intent'].'/refunds', ['amount' => '10.00'])->assertCreated();
        $this->postJson('/api/v1/payment-intents/'.$second['intent'].'/refunds')->assertCreated();

        self::assertCount(2, $this->getJson('/api/v1/refunds')->assertOk()->json('data'));
        self::assertSame(['10.00'], array_column($this->getJson('/api/v1/refunds?payment_intent_id='.$first['intent'])->json('data'), 'amount'));
        self::assertCount(2, $this->getJson('/api/v1/refunds?status=succeeded')->json('data'));
        $this->getJson('/api/v1/refunds?status=lost')->assertUnprocessable();
        self::assertCount(1, $this->getJson('/api/v1/refunds?per_page=1')->json('data'));

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
        $this->getJson('/api/v1/refunds')->assertOk();
        $this->postJson('/api/v1/payment-intents/'.$first['intent'].'/refunds')->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::PaymentsWrite]);
        $this->postJson('/api/v1/payment-intents/'.$first['intent'].'/refunds', ['amount' => '1.00'])->assertCreated();

        $this->actingAsMember(Tenant::factory()->create(), MembershipRole::Owner);
        $this->getJson('/api/v1/refunds')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/payment-intents/'.$first['intent'].'/refunds')->assertNotFound();
    }

    /**
     * @return TestResponse<Response>
     */
    private function deliverChargeRefunded(string $paymentRef): TestResponse
    {
        $event = json_decode((string) file_get_contents(base_path('tests/Fixtures/stripe/event.charge.refunded.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        $event['id'] = 'evt_'.Str::random(24);
        $event['data']['object']['payment_intent'] = $paymentRef;
        $payload = (string) json_encode($event);

        return $this->call('POST', '/api/v1/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($payload, self::SECRET, CarbonImmutable::now()->getTimestamp()),
        ], content: $payload);
    }
}
