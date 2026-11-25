<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The payment provider, as Payments needs it. StripeGateway talks to Stripe;
 * FakeGateway keeps intents in the cache, for tests and for local work
 * without network access.
 *
 * Every call that moves money carries an idempotency key that the provider
 * honours, so retrying after a timeout never charges twice. A decline is
 * not an exception: the intent comes back failed, with the provider's code.
 */
interface PaymentGateway
{
    /**
     * Stored with every intent: "stripe" or "fake".
     */
    public function name(): string;

    /**
     * @param  string  $reference  our payment intent id, kept by the provider as metadata
     *
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected
     */
    public function createIntent(GatewayAccount $account, Money $amount, string $reference, string $idempotencyKey): GatewayIntent;

    /**
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected
     */
    public function retrieve(GatewayAccount $account, string $gatewayRef): GatewayIntent;

    /**
     * Takes the money of an authorised intent.
     *
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected when the intent is not authorised (or no longer)
     */
    public function capture(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent;

    /**
     * Releases an intent that has not been captured.
     *
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected when the intent already succeeded
     */
    public function cancel(GatewayAccount $account, string $gatewayRef, string $idempotencyKey): GatewayIntent;

    /**
     * Gives back part or all of a captured payment.
     *
     * @param  string  $reference  our refund id, kept by the provider as metadata
     *
     * @throws PaymentGatewayUnavailable
     * @throws GatewayRejected when the provider will not refund (more than is left, say)
     */
    public function refund(GatewayAccount $account, string $gatewayRef, Money $amount, string $reference, string $idempotencyKey): GatewayRefund;

    /**
     * Every refund of a payment, including those made outside Vendra (in
     * the provider's dashboard).
     *
     * @return list<GatewayRefund>
     *
     * @throws PaymentGatewayUnavailable
     */
    public function refunds(GatewayAccount $account, string $gatewayRef): array;

    /**
     * Every movement on the account's balance since the instant, oldest
     * first; for reconciliation.
     *
     * @return iterable<GatewayBalanceTransaction>
     *
     * @throws PaymentGatewayUnavailable
     */
    public function balanceTransactions(GatewayAccount $account, CarbonImmutable $since): iterable;
}
