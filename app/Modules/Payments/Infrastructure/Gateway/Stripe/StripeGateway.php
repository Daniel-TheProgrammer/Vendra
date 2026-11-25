<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateway\Stripe;

use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\GatewayIntent;
use App\Modules\Payments\Application\Gateway\GatewayRefund;
use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stripe over its REST API, through Laravel's HTTP client (ADR 0009): a
 * dozen form-encoded calls do not justify the SDK, and Http::fake() lets
 * the contract tests check every request we send.
 *
 * - The API version is pinned with Stripe-Version; the fixtures under
 *   tests/Fixtures/stripe follow it.
 * - Calls act on the tenant's connected account (Stripe-Account).
 * - Calls that change something carry an Idempotency-Key, so the one retry
 *   on a dropped connection, and any retry by our caller, is safe.
 * - Intents are created with manual capture: the customer authorises, and
 *   the money is taken by POST /payment-intents/{id}/capture.
 * - Test mode only: a live key is refused when the gateway is built.
 */
final readonly class StripeGateway implements PaymentGateway
{
    public const API_VERSION = '2024-06-20';

    /** Expanded on every read of an intent, so a captured one carries its fee. */
    private const EXPAND = ['latest_charge.balance_transaction'];

    public function __construct(
        private Http $http,
        private LoggerInterface $logger,
        private string $secretKey,
        private string $baseUrl = 'https://api.stripe.com',
        private int $timeoutSeconds = 10,
    ) {
        if (preg_match('/^(sk|rk)_test_\w+$/', $secretKey) !== 1) {
            throw new InvalidArgumentException('STRIPE_SECRET must be a Stripe test-mode key (sk_test_… or rk_test_…). Vendra never moves real money.');
        }
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createIntent(GatewayAccount $account, Money $amount, string $reference, string $idempotencyKey): GatewayIntent
    {
        $object = $this->send(fn (): Response => $this->request($account, $idempotencyKey)->post('/v1/payment_intents', [
            'amount' => $amount->amount,
            'currency' => strtolower($amount->currency->value),
            'capture_method' => 'manual',
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['vendra_payment_intent_id' => $reference],
        ]));

        return StripeObjects::paymentIntent($object, withSecret: true);
    }

    public function retrieve(GatewayAccount $account, string $gatewayRef): GatewayIntent
    {
        return StripeObjects::paymentIntent($this->send(fn (): Response => $this->request($account)->get(
            '/v1/payment_intents/'.rawurlencode($gatewayRef),
            ['expand' => self::EXPAND],
        )));
    }

    public function capture(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent
    {
        return StripeObjects::paymentIntent($this->send(fn (): Response => $this->request($account, $idempotencyKey)->post(
            '/v1/payment_intents/'.rawurlencode($gatewayRef).'/capture',
            ['expand' => self::EXPAND],
        )));
    }

    public function cancel(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent
    {
        return StripeObjects::paymentIntent($this->send(fn (): Response => $this->request($account, $idempotencyKey)->post(
            '/v1/payment_intents/'.rawurlencode($gatewayRef).'/cancel',
        )));
    }

    public function refund(GatewayAccount $account, string $gatewayRef, Money $amount, string $reference, string $idempotencyKey): GatewayRefund
    {
        return StripeObjects::refund($this->send(fn (): Response => $this->request($account, $idempotencyKey)->post('/v1/refunds', [
            'payment_intent' => $gatewayRef,
            'amount' => $amount->amount,
            'metadata' => ['vendra_refund_id' => $reference],
        ])));
    }

    /**
     * GET /v1/refunds?payment_intent=…; a payment has a handful of refunds
     * at most, so one page of 100 holds them all.
     */
    public function refunds(GatewayAccount $account, string $gatewayRef): array
    {
        $list = $this->send(fn (): Response => $this->request($account)->get('/v1/refunds', ['payment_intent' => $gatewayRef, 'limit' => 100]));
        $refunds = [];

        foreach (is_array($list['data'] ?? null) ? $list['data'] : [] as $object) {
            if (is_array($object)) {
                /** @var array<string, mixed> $object */
                $refunds[] = StripeObjects::refund($object);
            }
        }

        return $refunds;
    }

    /**
     * GET /v1/balance_transactions, every page, with the source expanded so
     * each movement names its payment intent.
     */
    public function balanceTransactions(GatewayAccount $account, CarbonImmutable $since): iterable
    {
        $page = [];
        $startingAfter = null;

        do {
            $query = ['created' => ['gte' => $since->getTimestamp()], 'limit' => 100, 'expand' => ['data.source']];

            if ($startingAfter !== null) {
                $query['starting_after'] = $startingAfter;
            }

            $list = $this->send(fn (): Response => $this->request($account)->get('/v1/balance_transactions', $query));
            $data = is_array($list['data'] ?? null) ? $list['data'] : [];

            foreach ($data as $object) {
                if (is_array($object)) {
                    /** @var array<string, mixed> $object */
                    $page[] = StripeObjects::balanceTransaction($object);
                    $startingAfter = is_string($object['id'] ?? null) ? $object['id'] : $startingAfter;
                }
            }
        } while (($list['has_more'] ?? false) === true && $data !== []);

        // Stripe lists newest first.
        return array_reverse($page);
    }

    private function request(GatewayAccount $account, ?string $idempotencyKey = null): PendingRequest
    {
        $headers = ['Stripe-Version' => self::API_VERSION, 'Stripe-Account' => $account->accountId];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->http->baseUrl($this->baseUrl)
            ->withToken($this->secretKey)
            ->withHeaders($headers)
            ->acceptJson()
            ->asForm()
            ->connectTimeout(3)
            ->timeout($this->timeoutSeconds)
            // One retry, for a connection that never got an answer. Every
            // write carries an idempotency key, so this cannot double-act.
            ->retry(2, 250, static fn (Throwable $error): bool => $error instanceof ConnectionException, throw: false);
    }

    /**
     * The payment intent a call returned, from a success or from a card
     * error (a decline on capture comes back as 402 with the failed intent).
     *
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $unreachable) {
            throw PaymentGatewayUnavailable::because('Stripe could not be reached', $unreachable);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful()) {
            return $body;
        }

        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        if (($error['type'] ?? null) === 'card_error' && is_array($error['payment_intent'] ?? null)) {
            return $error['payment_intent'];
        }

        if ($response->serverError() || $response->status() === 429) {
            throw PaymentGatewayUnavailable::because(sprintf('Stripe answered %d', $response->status()));
        }

        if (in_array($response->status(), [401, 403], true)) {
            // Our configuration, not the client's request: page someone.
            $this->logger->critical('Stripe refused Vendra\'s credentials.', ['status' => $response->status(), 'message' => $error['message'] ?? null]);

            throw PaymentGatewayUnavailable::because('Stripe refused the platform credentials');
        }

        $code = $error['code'] ?? $error['type'] ?? null;
        $message = $error['message'] ?? null;

        throw GatewayRejected::because(
            is_string($code) ? $code : 'invalid_request_error',
            is_string($message) ? $message : sprintf('Stripe refused the request (%d).', $response->status()),
        );
    }
}
