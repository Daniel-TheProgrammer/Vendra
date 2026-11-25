<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateway;

use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\GatewayIntent;
use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * A payment provider that never leaves the process: intents live in the
 * cache, so they survive between requests locally and vanish between tests.
 * It keeps Stripe's rules where they matter (idempotency keys, only an
 * authorised intent can be captured, a captured one cannot be cancelled)
 * and charges Stripe's standard card fee, 2.9% + 30.
 *
 * Tests and local scripts drive the customer's side with authorise(),
 * decline() and failNextCall().
 */
final readonly class FakeGateway implements PaymentGateway
{
    private const PREFIX = 'payments:fake:';

    private const TTL_SECONDS = 86_400;

    public function __construct(
        private Cache $cache,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createIntent(GatewayAccount $account, Money $amount, string $reference, string $idempotencyKey): GatewayIntent
    {
        $this->interruptIfAsked();

        $existing = $this->cache->get(self::PREFIX.'key:'.$account->accountId.':'.$idempotencyKey);

        if (is_string($existing)) {
            return $this->toIntent($this->load($account, $existing), withSecret: true);
        }

        $ref = 'pi_fake_'.Str::lower(Str::random(24));
        $this->store($account, [
            'id' => $ref,
            'reference' => $reference,
            'amount' => $amount->amount,
            'currency' => $amount->currency->value,
            'status' => PaymentIntentStatus::RequiresPayment->value,
            'client_secret' => $ref.'_secret_'.Str::random(16),
            'decline' => null,
            'fee' => null,
        ]);
        $this->cache->put(self::PREFIX.'key:'.$account->accountId.':'.$idempotencyKey, $ref, self::TTL_SECONDS);

        return $this->toIntent($this->load($account, $ref), withSecret: true);
    }

    public function retrieve(GatewayAccount $account, string $gatewayRef): GatewayIntent
    {
        $this->interruptIfAsked();

        return $this->toIntent($this->load($account, $gatewayRef));
    }

    public function capture(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent
    {
        $this->interruptIfAsked();
        $intent = $this->load($account, $gatewayRef);

        if ($intent['status'] === PaymentIntentStatus::Succeeded->value) {
            return $this->toIntent($intent);
        }

        if ($intent['status'] !== PaymentIntentStatus::Processing->value) {
            throw GatewayRejected::because('payment_intent_unexpected_state', sprintf('This PaymentIntent could not be captured because it has a status of %s.', $intent['status']));
        }

        if ($intent['decline'] !== null) {
            $intent['status'] = PaymentIntentStatus::Failed->value;
        } else {
            $intent['status'] = PaymentIntentStatus::Succeeded->value;
            $intent['fee'] = min($intent['amount'], intdiv($intent['amount'] * 29 + 500, 1000) + 30);
        }

        $this->store($account, $intent);

        return $this->toIntent($intent);
    }

    public function cancel(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent
    {
        $this->interruptIfAsked();
        $intent = $this->load($account, $gatewayRef);

        if (in_array($intent['status'], [PaymentIntentStatus::Succeeded->value, PaymentIntentStatus::Failed->value], true)) {
            throw GatewayRejected::because('payment_intent_unexpected_state', sprintf('You cannot cancel this PaymentIntent because it has a status of %s.', $intent['status']));
        }

        $intent['status'] = PaymentIntentStatus::Cancelled->value;
        $this->store($account, $intent);

        return $this->toIntent($intent);
    }

    /**
     * The customer confirms the payment in their browser: the money is held.
     */
    public function authorise(GatewayAccount $account, string $gatewayRef): void
    {
        $intent = $this->load($account, $gatewayRef);
        $intent['status'] = PaymentIntentStatus::Processing->value;
        $this->store($account, $intent);
    }

    /**
     * The card will be declined when the intent is captured.
     */
    public function decline(GatewayAccount $account, string $gatewayRef, string $code = 'card_declined'): void
    {
        $intent = $this->load($account, $gatewayRef);
        $intent['status'] = PaymentIntentStatus::Processing->value;
        $intent['decline'] = $code;
        $this->store($account, $intent);
    }

    /**
     * The next call fails as if the network had dropped.
     */
    public function failNextCall(): void
    {
        $this->cache->put(self::PREFIX.'interrupt', true, self::TTL_SECONDS);
    }

    private function interruptIfAsked(): void
    {
        if ($this->cache->pull(self::PREFIX.'interrupt') === true) {
            throw PaymentGatewayUnavailable::because('connection timed out');
        }
    }

    /**
     * @return array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}
     */
    private function load(GatewayAccount $account, string $gatewayRef): array
    {
        $intent = $this->cache->get(self::PREFIX.'intent:'.$account->accountId.':'.$gatewayRef);

        if (! is_array($intent)) {
            throw GatewayRejected::because('resource_missing', sprintf('No such payment_intent: \'%s\'', $gatewayRef));
        }

        /** @var array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null} $intent */
        return $intent;
    }

    /**
     * @param  array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}  $intent
     */
    private function store(GatewayAccount $account, array $intent): void
    {
        $this->cache->put(self::PREFIX.'intent:'.$account->accountId.':'.$intent['id'], $intent, self::TTL_SECONDS);
    }

    /**
     * @param  array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}  $intent
     */
    private function toIntent(array $intent, bool $withSecret = false): GatewayIntent
    {
        $currency = Currency::from($intent['currency']);
        $declined = $intent['status'] === PaymentIntentStatus::Failed->value;

        return new GatewayIntent(
            reference: $intent['id'],
            status: PaymentIntentStatus::from($intent['status']),
            amount: Money::of($intent['amount'], $currency),
            clientSecret: $withSecret ? $intent['client_secret'] : null,
            fee: $intent['fee'] !== null ? Money::of($intent['fee'], $currency) : null,
            failureCode: $declined ? $intent['decline'] : null,
            failureMessage: $declined ? 'Your card was declined.' : null,
        );
    }
}
