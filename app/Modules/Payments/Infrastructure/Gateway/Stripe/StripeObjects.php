<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Gateway\Stripe;

use App\Modules\Payments\Application\Gateway\GatewayBalanceTransaction;
use App\Modules\Payments\Application\Gateway\GatewayIntent;
use App\Modules\Payments\Application\Gateway\GatewayRefund;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Domain\RefundStatus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * Reads Stripe objects (API 2024-06-20) into our terms. Used for API
 * responses and for the objects inside webhook events alike.
 *
 * Stripe's intent statuses fold into ours:
 *
 *   requires_payment_method, requires_confirmation, requires_action → requires_payment
 *     (requires_payment_method after an attempt that left last_payment_error → failed)
 *   requires_capture, processing  → processing (authorised, money held)
 *   succeeded                     → succeeded
 *   canceled                      → cancelled
 *
 * Amounts are in the currency's smallest unit on both sides, as Stripe
 * and Money agree on the minor unit for every currency Vendra prices in.
 */
final class StripeObjects
{
    /**
     * @param  array<string, mixed>  $object  a payment_intent
     */
    public static function paymentIntent(array $object, bool $withSecret = false): GatewayIntent
    {
        $id = self::string($object, 'id');
        $currency = Currency::tryFrom(strtoupper(self::string($object, 'currency')))
            ?? throw new UnexpectedValueException(sprintf('Stripe intent %s is in a currency Vendra does not price in.', $id));
        $error = is_array($object['last_payment_error'] ?? null) ? $object['last_payment_error'] : null;
        $status = self::status(self::string($object, 'status'), $error !== null);
        $failed = $status === PaymentIntentStatus::Failed;

        return new GatewayIntent(
            reference: $id,
            status: $status,
            amount: Money::of(self::int($object, 'amount'), $currency),
            clientSecret: $withSecret && is_string($object['client_secret'] ?? null) ? $object['client_secret'] : null,
            fee: self::fee($object, $currency),
            failureCode: $failed ? self::failureCode($error) : null,
            failureMessage: $failed && is_string($error['message'] ?? null) ? $error['message'] : null,
        );
    }

    /**
     * Refund statuses: pending and requires_action wait, succeeded is done,
     * failed and canceled gave nothing back.
     *
     * @param  array<string, mixed>  $object  a refund
     */
    public static function refund(array $object): GatewayRefund
    {
        $id = self::string($object, 'id');
        $currency = Currency::tryFrom(strtoupper(self::string($object, 'currency')))
            ?? throw new UnexpectedValueException(sprintf('Refund %s is in a currency Vendra does not price in.', $id));
        $status = match (self::string($object, 'status')) {
            'pending', 'requires_action' => RefundStatus::Pending,
            'succeeded' => RefundStatus::Succeeded,
            'failed', 'canceled' => RefundStatus::Failed,
            default => throw new UnexpectedValueException(sprintf('Unknown Stripe refund status "%s".', self::string($object, 'status'))),
        };
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $ours = $metadata['vendra_refund_id'] ?? null;
        $failure = $object['failure_reason'] ?? null;

        return new GatewayRefund(
            reference: $id,
            paymentRef: self::string($object, 'payment_intent'),
            status: $status,
            amount: Money::of(self::int($object, 'amount'), $currency),
            ourId: is_string($ours) ? $ours : null,
            failureCode: $status === RefundStatus::Failed ? (is_string($failure) ? $failure : 'refund_failed') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $object  a balance_transaction, source expanded
     */
    public static function balanceTransaction(array $object): GatewayBalanceTransaction
    {
        $currency = Currency::tryFrom(strtoupper(self::string($object, 'currency')))
            ?? throw new UnexpectedValueException(sprintf('Balance transaction %s is in a currency Vendra does not price in.', self::string($object, 'id')));
        $source = is_array($object['source'] ?? null) ? $object['source'] : [];
        $paymentRef = $source['payment_intent'] ?? null;

        return new GatewayBalanceTransaction(
            id: self::string($object, 'id'),
            type: match (self::string($object, 'type')) {
                'charge', 'payment' => GatewayBalanceTransaction::CHARGE,
                'refund', 'payment_refund' => GatewayBalanceTransaction::REFUND,
                default => self::string($object, 'type'),
            },
            amount: Money::of(self::int($object, 'amount'), $currency),
            fee: Money::of(self::int($object, 'fee'), $currency),
            net: Money::of(self::int($object, 'net'), $currency),
            paymentRef: is_string($paymentRef) ? $paymentRef : null,
            createdAt: CarbonImmutable::createFromTimestampUTC(self::int($object, 'created')),
        );
    }

    private static function status(string $stripeStatus, bool $attemptFailed): PaymentIntentStatus
    {
        return match ($stripeStatus) {
            'requires_payment_method' => $attemptFailed ? PaymentIntentStatus::Failed : PaymentIntentStatus::RequiresPayment,
            'requires_confirmation', 'requires_action' => PaymentIntentStatus::RequiresPayment,
            'requires_capture', 'processing' => PaymentIntentStatus::Processing,
            'succeeded' => PaymentIntentStatus::Succeeded,
            'canceled' => PaymentIntentStatus::Cancelled,
            default => throw new UnexpectedValueException(sprintf('Unknown Stripe payment intent status "%s".', $stripeStatus)),
        };
    }

    /**
     * The fee is on the charge's balance transaction, present when the
     * request expanded latest_charge.balance_transaction.
     *
     * @param  array<string, mixed>  $object
     */
    private static function fee(array $object, Currency $currency): ?Money
    {
        $charge = $object['latest_charge'] ?? null;
        $transaction = is_array($charge) ? ($charge['balance_transaction'] ?? null) : null;

        return is_array($transaction) && is_int($transaction['fee'] ?? null) ? Money::of($transaction['fee'], $currency) : null;
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    private static function failureCode(?array $error): string
    {
        foreach (['decline_code', 'code', 'type'] as $field) {
            if (is_string($error[$field] ?? null) && $error[$field] !== '') {
                return $error[$field];
            }
        }

        return 'payment_failed';
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private static function string(array $object, string $field): string
    {
        $value = $object[$field] ?? null;

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('Stripe object without a string "%s".', $field));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private static function int(array $object, string $field): int
    {
        $value = $object[$field] ?? null;

        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('Stripe object without an integer "%s".', $field));
    }
}
