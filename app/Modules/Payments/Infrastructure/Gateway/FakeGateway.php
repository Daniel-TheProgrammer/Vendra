<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateway;

use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\GatewayBalanceTransaction;
use App\Modules\Payments\Application\Gateway\GatewayIntent;
use App\Modules\Payments\Application\Gateway\GatewayRefund;
use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Domain\RefundStatus;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * A payment provider that never leaves the process: intents live in the
 * cache, so they survive between requests locally and vanish between tests.
 * It keeps Stripe's rules where they matter (idempotency keys, only an
 * authorised intent can be captured, a captured one cannot be cancelled)
 * and charges Stripe's standard card fee, 2.9% + 30.
 *
 * Tests and local scripts drive the customer's and the provider's side with
 * authorise(), decline(), succeed() and failNextCall().
 */
final readonly class FakeGateway implements PaymentGateway
{
    private const PREFIX = 'payments:fake:';

    /** Long enough for any reconciliation window, as a real provider keeps history. */
    private const TTL_SECONDS = 90 * 86_400;

    public function __construct(
        private Cache $cache,
        private Clock $clock,
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
            $this->store($account, $intent);

            return $this->toIntent($intent);
        }

        return $this->toIntent($this->collect($account, $intent));
    }

    public function refund(GatewayAccount $account, string $gatewayRef, Money $amount, string $reference, string $idempotencyKey): GatewayRefund
    {
        $this->interruptIfAsked();

        $existing = $this->cache->get(self::PREFIX.'key:'.$account->accountId.':'.$idempotencyKey);

        if (is_string($existing)) {
            foreach ($this->refundsOf($account, $gatewayRef) as $refund) {
                if ($refund['id'] === $existing) {
                    return $this->toRefund($refund);
                }
            }
        }

        $refund = $this->issueRefund($account, $gatewayRef, $amount->amount, $reference);
        $this->cache->put(self::PREFIX.'key:'.$account->accountId.':'.$idempotencyKey, $refund['id'], self::TTL_SECONDS);

        return $this->toRefund($refund);
    }

    public function refunds(GatewayAccount $account, string $gatewayRef): array
    {
        $this->interruptIfAsked();
        $this->load($account, $gatewayRef);

        return array_map($this->toRefund(...), $this->refundsOf($account, $gatewayRef));
    }

    /**
     * Someone refunds in the provider's dashboard, outside Vendra.
     */
    public function refundAtProvider(GatewayAccount $account, string $gatewayRef, int $amount): string
    {
        return $this->issueRefund($account, $gatewayRef, $amount, null)['id'];
    }

    /**
     * New refunds stay pending until settleRefund(), as bank transfers do.
     */
    public function holdRefunds(): void
    {
        $this->cache->put(self::PREFIX.'hold-refunds', true, self::TTL_SECONDS);
    }

    public function settleRefund(GatewayAccount $account, string $gatewayRef, string $refundRef, RefundStatus $status): void
    {
        $refunds = $this->refundsOf($account, $gatewayRef);

        foreach ($refunds as $index => $refund) {
            if ($refund['id'] === $refundRef) {
                $refunds[$index]['status'] = $status->value;

                if ($status === RefundStatus::Succeeded) {
                    $this->move($account, GatewayBalanceTransaction::REFUND, -$refund['amount'], 0, $this->load($account, $gatewayRef));
                }
            }
        }

        $this->cache->put(self::PREFIX.'refunds:'.$account->accountId.':'.$gatewayRef, $refunds, self::TTL_SECONDS);
    }

    /**
     * @return array{id: string, payment_ref: string, amount: int, currency: string, status: string, reference: string|null}
     */
    private function issueRefund(GatewayAccount $account, string $gatewayRef, int $amount, ?string $reference): array
    {
        $intent = $this->load($account, $gatewayRef);

        if ($intent['status'] !== PaymentIntentStatus::Succeeded->value) {
            throw GatewayRejected::because('charge_not_refundable', 'This PaymentIntent has not been captured; there is nothing to refund.');
        }

        $refunds = $this->refundsOf($account, $gatewayRef);
        $refunded = array_sum(array_map(static fn (array $r): int => $r['status'] === RefundStatus::Failed->value ? 0 : $r['amount'], $refunds));

        if ($amount > $intent['amount'] - $refunded) {
            throw GatewayRejected::because('amount_too_large', sprintf('Refund amount (%d) is greater than unrefunded amount on charge (%d).', $amount, $intent['amount'] - $refunded));
        }

        $held = $this->cache->get(self::PREFIX.'hold-refunds') === true;
        $refund = [
            'id' => 're_fake_'.Str::lower(Str::random(24)),
            'payment_ref' => $gatewayRef,
            'amount' => $amount,
            'currency' => $intent['currency'],
            'status' => ($held ? RefundStatus::Pending : RefundStatus::Succeeded)->value,
            'reference' => $reference,
        ];
        $refunds[] = $refund;
        $this->cache->put(self::PREFIX.'refunds:'.$account->accountId.':'.$gatewayRef, $refunds, self::TTL_SECONDS);

        if (! $held) {
            $this->move($account, GatewayBalanceTransaction::REFUND, -$amount, 0, $intent);
        }

        return $refund;
    }

    /**
     * @return list<array{id: string, payment_ref: string, amount: int, currency: string, status: string, reference: string|null}>
     */
    private function refundsOf(GatewayAccount $account, string $gatewayRef): array
    {
        $refunds = $this->cache->get(self::PREFIX.'refunds:'.$account->accountId.':'.$gatewayRef, []);

        if (! is_array($refunds)) {
            return [];
        }

        /** @var list<array{id: string, payment_ref: string, amount: int, currency: string, status: string, reference: string|null}> $refunds */
        return $refunds;
    }

    /**
     * @param  array{id: string, payment_ref: string, amount: int, currency: string, status: string, reference: string|null}  $refund
     */
    private function toRefund(array $refund): GatewayRefund
    {
        return new GatewayRefund(
            reference: $refund['id'],
            paymentRef: $refund['payment_ref'],
            status: RefundStatus::from($refund['status']),
            amount: Money::of($refund['amount'], Currency::from($refund['currency'])),
            ourId: $refund['reference'],
            failureCode: $refund['status'] === RefundStatus::Failed->value ? 'expired_or_canceled_card' : null,
        );
    }

    public function balanceTransactions(GatewayAccount $account, CarbonImmutable $since): iterable
    {
        $this->interruptIfAsked();

        foreach ($this->balance($account) as $row) {
            $createdAt = CarbonImmutable::parse($row['created_at']);

            if ($createdAt->lessThan($since)) {
                continue;
            }

            $currency = Currency::from($row['currency']);

            yield new GatewayBalanceTransaction(
                id: $row['id'],
                type: $row['type'],
                amount: Money::of($row['amount'], $currency),
                fee: Money::of($row['fee'], $currency),
                net: Money::of($row['amount'] - $row['fee'], $currency),
                paymentRef: $row['payment_ref'],
                createdAt: $createdAt,
            );
        }
    }

    /**
     * The provider takes the money without our capture call: what a webhook
     * then reports (or what a crash between capture and commit leaves).
     */
    public function succeed(GatewayAccount $account, string $gatewayRef): void
    {
        $this->collect($account, $this->load($account, $gatewayRef));
    }

    /**
     * The money is taken: the fee is charged and the movement appears on
     * the balance.
     *
     * @param  array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}  $intent
     * @return array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}
     */
    private function collect(GatewayAccount $account, array $intent): array
    {
        $intent['status'] = PaymentIntentStatus::Succeeded->value;
        $intent['fee'] = min($intent['amount'], intdiv($intent['amount'] * 29 + 500, 1000) + 30);
        $this->store($account, $intent);

        $this->move($account, GatewayBalanceTransaction::CHARGE, $intent['amount'], $intent['fee'], $intent);

        return $intent;
    }

    /**
     * @param  array{id: string, reference: string, amount: int, currency: string, status: string, client_secret: string, decline: string|null, fee: int|null}  $intent
     */
    private function move(GatewayAccount $account, string $type, int $amount, int $fee, array $intent): void
    {
        $balance = $this->balance($account);
        $balance[] = [
            'id' => 'txn_fake_'.Str::lower(Str::random(24)),
            'type' => $type,
            'amount' => $amount,
            'fee' => $fee,
            'currency' => $intent['currency'],
            'payment_ref' => $intent['id'],
            'created_at' => $this->clock->now()->toIso8601String(),
        ];
        $this->cache->put(self::PREFIX.'balance:'.$account->accountId, $balance, self::TTL_SECONDS);
    }

    /**
     * @return list<array{id: string, type: string, amount: int, fee: int, currency: string, payment_ref: string|null, created_at: string}>
     */
    private function balance(GatewayAccount $account): array
    {
        $balance = $this->cache->get(self::PREFIX.'balance:'.$account->accountId, []);

        if (! is_array($balance)) {
            return [];
        }

        /** @var list<array{id: string, type: string, amount: int, fee: int, currency: string, payment_ref: string|null, created_at: string}> $balance */
        return $balance;
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
