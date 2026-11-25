<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Feature;

use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\GatewayBalanceTransaction;
use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Gateway\Stripe\StripeGateway;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * What StripeGateway sends to Stripe and how it reads the answers, against
 * the fixtures in tests/Fixtures/stripe (API 2024-06-20).
 */
final class StripeGatewayContractTest extends TestCase
{
    private const SECRET = 'sk_test_51Vendra0Contract0Tests';

    private const INTENT = 'pi_3QK1ZBJt8fA2kVnl0f4hXc2e';

    private GatewayAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = new GatewayAccount('acct_1TestMerchant42');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('tests/Fixtures/stripe/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function gateway(): StripeGateway
    {
        // Built after Http::fake(), so it holds the faked client.
        return new StripeGateway($this->app->make(HttpFactory::class), $this->app->make(LoggerInterface::class), self::SECRET);
    }

    private static function sentToStripe(Request $request, string $method, string $path, ?string $idempotencyKey): bool
    {
        return $request->method() === $method
            && str_starts_with($request->url(), 'https://api.stripe.com'.$path)
            && $request->header('Authorization') === ['Bearer '.self::SECRET]
            && $request->header('Stripe-Version') === [StripeGateway::API_VERSION]
            && $request->header('Stripe-Account') === ['acct_1TestMerchant42']
            && $request->header('Idempotency-Key') === ($idempotencyKey !== null ? [$idempotencyKey] : []);
    }

    #[Test]
    public function an_intent_is_created_for_manual_capture_on_the_tenants_account(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(self::fixture('payment_intent.created'))]);

        $intent = $this->gateway()->createIntent($this->account, Money::of(10000, Currency::USD), '0195d5a8-1c2d-7e3f-8a4b-5c6d7e8f9a0b', 'intent:0195d5a8');

        self::assertSame(self::INTENT, $intent->reference);
        self::assertSame(PaymentIntentStatus::RequiresPayment, $intent->status);
        self::assertTrue($intent->amount->equals(Money::of(10000, Currency::USD)));
        self::assertSame(self::INTENT.'_secret_9XoQb1Lr3nUeTzA7sK2dPv5Wm', $intent->clientSecret);
        self::assertNull($intent->fee);

        Http::assertSent(static fn (Request $request): bool => self::sentToStripe($request, 'POST', '/v1/payment_intents', 'intent:0195d5a8')
            && $request->isForm()
            && $request->data() === [
                'amount' => 10000,
                'currency' => 'usd',
                'capture_method' => 'manual',
                'automatic_payment_methods' => ['enabled' => 'true'],
                'metadata' => ['vendra_payment_intent_id' => '0195d5a8-1c2d-7e3f-8a4b-5c6d7e8f9a0b'],
            ]);
    }

    #[Test]
    public function an_authorised_intent_reads_as_processing_and_carries_no_secret(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/*' => Http::response(self::fixture('payment_intent.requires_capture'))]);

        $intent = $this->gateway()->retrieve($this->account, self::INTENT);

        self::assertSame(PaymentIntentStatus::Processing, $intent->status);
        self::assertNull($intent->clientSecret);
        Http::assertSent(static fn (Request $request): bool => self::sentToStripe($request, 'GET', '/v1/payment_intents/'.self::INTENT, null)
            && str_contains(urldecode($request->url()), 'expand[0]=latest_charge.balance_transaction'));
    }

    #[Test]
    public function a_capture_returns_the_fee_from_the_expanded_balance_transaction(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/*/capture' => Http::response(self::fixture('payment_intent.succeeded'))]);

        $intent = $this->gateway()->capture($this->account, self::INTENT, 'capture:0195d5a8');

        self::assertSame(PaymentIntentStatus::Succeeded, $intent->status);
        self::assertTrue($intent->fee?->equals(Money::of(320, Currency::USD)));
        Http::assertSent(static fn (Request $request): bool => self::sentToStripe($request, 'POST', '/v1/payment_intents/'.self::INTENT.'/capture', 'capture:0195d5a8')
            && $request->data() === ['expand' => ['latest_charge.balance_transaction']]);
    }

    #[Test]
    public function a_decline_on_capture_is_a_failed_intent_not_an_error(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(self::fixture('error.card_declined'), 402)]);

        $intent = $this->gateway()->capture($this->account, self::INTENT, 'capture:0195d5a8');

        self::assertSame(PaymentIntentStatus::Failed, $intent->status);
        self::assertSame('insufficient_funds', $intent->failureCode);
        self::assertSame('Your card has insufficient funds.', $intent->failureMessage);
    }

    #[Test]
    public function a_cancelled_intent_reads_as_cancelled(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/*/cancel' => Http::response(self::fixture('payment_intent.canceled'))]);

        self::assertSame(PaymentIntentStatus::Cancelled, $this->gateway()->cancel($this->account, self::INTENT, 'cancel:0195d5a8')->status);
        Http::assertSent(static fn (Request $request): bool => self::sentToStripe($request, 'POST', '/v1/payment_intents/'.self::INTENT.'/cancel', 'cancel:0195d5a8'));
    }

    #[Test]
    public function stripe_refusing_the_request_is_a_rejection_with_its_code(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(self::fixture('error.payment_intent_unexpected_state'), 400)]);

        try {
            $this->gateway()->capture($this->account, self::INTENT, 'capture:0195d5a8');
            self::fail('Stripe refused the capture.');
        } catch (GatewayRejected $rejected) {
            self::assertSame('payment_intent_unexpected_state', $rejected->gatewayCode());
            self::assertStringStartsWith('This PaymentIntent could not be captured', $rejected->getMessage());
        }
    }

    #[Test]
    public function outages_rate_limits_and_bad_credentials_are_unavailability(): void
    {
        $statuses = [500, 503, 429];
        $sequence = Http::fakeSequence('api.stripe.com/*');

        foreach ($statuses as $status) {
            $sequence->push(['error' => ['type' => 'api_error', 'message' => 'boom']], $status);
        }

        $sequence->push(self::fixture('error.authentication'), 401);

        foreach ($statuses as $status) {
            try {
                $this->gateway()->retrieve($this->account, self::INTENT);
                self::fail(sprintf('%d must be unavailability.', $status));
            } catch (PaymentGatewayUnavailable $unavailable) {
                self::assertStringContainsString((string) $status, $unavailable->getMessage());
            }
        }

        $log = Log::spy();

        try {
            $this->gateway()->retrieve($this->account, self::INTENT);
            self::fail('A 401 must be unavailability.');
        } catch (PaymentGatewayUnavailable) {
        }

        $log->shouldHaveReceived('critical')->once();
    }

    #[Test]
    public function a_dropped_connection_is_retried_once_with_the_same_idempotency_key(): void
    {
        $attempts = 0;
        Http::fake(static function () use (&$attempts) {
            $attempts++;

            return $attempts === 1 ? throw new ConnectionException('Connection reset by peer') : Http::response(self::fixture('payment_intent.succeeded'));
        });

        self::assertSame(PaymentIntentStatus::Succeeded, $this->gateway()->capture($this->account, self::INTENT, 'capture:0195d5a8')->status);
        // The dropped attempt never got a response, so only the retry is
        // recorded; the counter saw both.
        self::assertSame(2, $attempts);
        Http::assertSent(static fn (Request $request): bool => $request->header('Idempotency-Key') === ['capture:0195d5a8']);

        Http::fake(static fn (): never => throw new ConnectionException('Connection timed out'));
        $this->expectException(PaymentGatewayUnavailable::class);
        $this->gateway()->capture($this->account, self::INTENT, 'capture:0195d5a8');
    }

    #[Test]
    public function balance_transactions_are_read_across_pages_oldest_first(): void
    {
        Http::fakeSequence('api.stripe.com/v1/balance_transactions*')
            ->push(self::fixture('balance_transactions.page1'))
            ->push(self::fixture('balance_transactions.page2'));

        $since = CarbonImmutable::createFromTimestampUTC(1763900000);
        $movements = [...$this->gateway()->balanceTransactions($this->account, $since)];

        self::assertSame(['payout', 'charge', 'refund'], array_map(static fn (GatewayBalanceTransaction $m): string => $m->type, $movements));
        self::assertSame([null, self::INTENT, self::INTENT], array_map(static fn (GatewayBalanceTransaction $m): ?string => $m->paymentRef, $movements));
        self::assertSame([9680, 320], [$movements[1]->net->amount, $movements[1]->fee->amount]);
        self::assertSame(-2500, $movements[2]->net->amount);

        Http::assertSent(static fn (Request $request): bool => self::sentToStripe($request, 'GET', '/v1/balance_transactions', null)
            && str_contains(urldecode($request->url()), 'created[gte]=1763900000')
            && str_contains(urldecode($request->url()), 'expand[0]=data.source')
            && ! str_contains($request->url(), 'starting_after'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'starting_after=txn_3QK2AAJt8fA2kVnl1rF9gH0i'));
    }

    #[Test]
    public function live_keys_and_missing_keys_are_refused_up_front(): void
    {
        foreach (['sk_live_51RealMoney', 'rk_live_51RealMoney', ''] as $key) {
            try {
                new StripeGateway($this->app->make(HttpFactory::class), $this->app->make(LoggerInterface::class), $key);
                self::fail(sprintf('"%s" must be refused.', $key));
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString('test-mode', $refused->getMessage());
            }
        }
    }

    #[Test]
    public function the_container_builds_the_gateway_the_configuration_names(): void
    {
        config(['payments.gateway' => 'stripe', 'payments.stripe.secret' => self::SECRET]);
        $this->app->forgetInstance(PaymentGateway::class);

        self::assertSame('stripe', $this->app->make(PaymentGateway::class)->name());

        config(['payments.gateway' => 'paypal']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(PaymentGateway::class);
    }
}
