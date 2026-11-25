<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\PaymentSettlement;
use App\Modules\Payments\Application\Services\PaymentUpdate;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Gateway\FakeGateway;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * payments:reconcile against the fake provider's balance.
 */
#[Group('database')]
final class PaymentReconciliationTest extends TestCase
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
            $variant = ProductVariant::query()->where('product_id', Product::factory()->withVariant('PEN-1', 1000)->create()->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 100, AdjustmentReason::Received));
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

    /**
     * @return array{order: string, intent: string, ref: string}
     */
    private function startPayment(int $quantity = 3): array
    {
        $order = $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'buyer@example.com'],
            'lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]],
        ])->json('data.id');
        $intent = $this->postJson('/api/v1/orders/'.$order.'/payment-intents')->json('data.id');

        return ['order' => $order, 'intent' => $intent, 'ref' => $this->asTenant(static fn (): string => PaymentIntent::query()->whereKey($intent)->valueOrFail('gateway_ref'))];
    }

    private function capture(): string
    {
        $payment = $this->startPayment();
        $this->gateway->authorise(new GatewayAccount(self::ACCOUNT), $payment['ref']);
        $this->postJson('/api/v1/payment-intents/'.$payment['intent'].'/capture')->assertOk();

        return $payment['intent'];
    }

    #[Test]
    public function captured_payments_reconcile_with_the_provider_balance(): void
    {
        $this->capture();
        $this->capture();

        $this->runArtisan('payments:reconcile')
            ->expectsOutputToContain('no discrepancies')
            ->assertSuccessful();
    }

    #[Test]
    public function money_the_provider_took_without_us_settling_it_is_reported(): void
    {
        $this->capture();
        $lost = $this->startPayment();
        // Captured at the provider; the response and the webhook were lost.
        $this->gateway->succeed(new GatewayAccount(self::ACCOUNT), $lost['ref']);
        $log = Log::spy();

        $this->runArtisan('payments:reconcile')
            ->expectsOutputToContain('unsettled_charge '.$lost['ref'].': The provider charged 30.00 USD; Vendra has the intent requires_payment.')
            // 30.00 less 2.9% + 0.30 is 28.83, twice at the provider, once here.
            ->expectsOutputToContain('clearing_mismatch USD: Provider net 57.66, ledger Cash / Stripe Clearing 28.83.')
            ->assertFailed();

        $log->shouldHaveReceived('critical')->twice();
    }

    #[Test]
    public function a_fee_that_differs_from_the_provider_is_reported(): void
    {
        $intent = $this->capture();
        $this->asTenant(static fn () => PaymentIntent::query()->whereKey($intent)->update(['fee_minor' => 1]));

        $this->runArtisan('payments:reconcile')->expectsOutputToContain('fee_mismatch '.$intent.': Provider fee 1.17, recorded 0.01.')->assertFailed();
    }

    #[Test]
    public function a_payment_settled_here_without_a_charge_at_the_provider_is_reported(): void
    {
        $payment = $this->startPayment();
        $this->asTenant(fn () => $this->app->make(PaymentSettlement::class)->apply($payment['intent'], new PaymentUpdate(PaymentIntentStatus::Succeeded, fee: Money::of(0, Currency::USD))));

        $this->runArtisan('payments:reconcile')
            ->expectsOutputToContain('missing_charge '.$payment['intent'])
            ->expectsOutputToContain('Provider net 0.00, ledger Cash / Stripe Clearing 30.00.')
            ->assertFailed();
    }

    #[Test]
    public function only_movements_since_the_given_instant_are_compared(): void
    {
        $this->travelTo('2026-11-01T10:00:00Z');
        $this->capture();
        $this->travelTo('2026-11-20T10:00:00Z');

        $this->runArtisan('payments:reconcile', ['--since' => '2026-11-10'])->assertSuccessful();
        $this->runArtisan('payments:reconcile', ['--since' => '2026-10-31T00:00:00Z'])->assertSuccessful();
        $this->runArtisan('payments:reconcile', ['--since' => 'last tuesday'])->assertExitCode(2);
    }

    #[Test]
    public function an_unreachable_provider_fails_the_run_without_false_discrepancies(): void
    {
        $this->capture();
        $this->gateway->failNextCall();

        $this->runArtisan('payments:reconcile')->expectsOutputToContain('provider unavailable')->assertFailed();
    }
}
