<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Postings;

use App\Modules\Ledger\Application\Actions\PostJournalEntry;
use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Orders\Application\Queries\OrderSummary;
use App\Modules\Payments\Domain\Events\PaymentCaptured;
use App\Modules\Payments\Domain\Events\RefundIssued;
use App\Modules\Payments\Domain\Settlement;
use App\Shared\Domain\Money\Money;

/**
 * How money moving through the payment provider is booked (ADR 0008).
 *
 *   captured  Dr Cash / Stripe Clearing  amount - fee
 *             Dr Payment Fees            fee (when there is one)
 *               Cr Accounts Receivable     amount
 *                  (Customer Credits, when the order could no longer
 *                   take the money and it is owed back)
 *
 *   refunded  Dr Refunds                 refund less its share of tax
 *             Dr Tax Payable             the refund's share of tax
 *               Cr Cash / Stripe Clearing  refund
 *                  (Dr Customer Credits for the whole refund instead, when
 *                   the money was held as credit: there is no sale to undo)
 *
 * Tax is allocated cumulatively: after refunds totalling R of a payment for
 * an order of total T with tax X, floor(R × X / T) of tax has been returned.
 * Each refund takes the difference, so partial refunds never drift by a cent
 * and a full refund returns exactly the order's tax. The sale itself stays
 * booked; Refunds is its contra-revenue (ADR 0008).
 *
 * Keys are "payment:{intent id}:captured" and "refund:{refund id}:succeeded":
 * the provider's webhook and our own response both raise the event, and it
 * books once.
 */
final readonly class PaymentPostings
{
    public const REFERENCE_TYPE = 'payment';

    public const REFUND_REFERENCE_TYPE = 'refund';

    public function __construct(
        private Accounts $accounts,
        private PostJournalEntry $post,
    ) {}

    public function captured(PaymentCaptured $payment, OrderSummary $order): JournalEntryRecord
    {
        $currency = $payment->amount->currency;
        $net = $payment->amount->subtract($payment->fee);
        $credited = $payment->settlement === Settlement::Order ? ChartOfAccounts::ACCOUNTS_RECEIVABLE : ChartOfAccounts::CUSTOMER_CREDITS;

        $entry = JournalEntry::draft($currency, sprintf('Order %d paid', $order->number));

        if ($net->isPositive()) {
            $entry = $entry->debit($this->accounts->get(ChartOfAccounts::CASH_CLEARING, $currency)->id, $net);
        }

        if ($payment->fee->isPositive()) {
            $entry = $entry->debit($this->accounts->get(ChartOfAccounts::PAYMENT_FEES, $currency)->id, $payment->fee, 'Payment provider fee');
        }

        $entry = $entry->credit($this->accounts->get($credited, $currency)->id, $payment->amount);

        return ($this->post)($entry->post(), new EntryMetadata(
            idempotencyKey: sprintf('payment:%s:captured', $payment->paymentIntentId),
            referenceType: self::REFERENCE_TYPE,
            referenceId: $payment->paymentIntentId,
            postedAt: $payment->occurredAt,
        ));
    }

    public function refunded(RefundIssued $refund, OrderSummary $order): JournalEntryRecord
    {
        $currency = $refund->amount->currency;
        $entry = JournalEntry::draft($currency, sprintf('Order %d refunded', $order->number));

        if ($refund->settlement === Settlement::CustomerCredit) {
            $entry = $entry->debit($this->accounts->get(ChartOfAccounts::CUSTOMER_CREDITS, $currency)->id, $refund->amount);
        } else {
            $tax = self::taxShare($refund, $order);
            $net = $refund->amount->subtract($tax);

            if ($net->isPositive()) {
                $entry = $entry->debit($this->accounts->get(ChartOfAccounts::REFUNDS, $currency)->id, $net);
            }

            if ($tax->isPositive()) {
                $entry = $entry->debit($this->accounts->get(ChartOfAccounts::TAX_PAYABLE, $currency)->id, $tax, 'Tax refunded');
            }
        }

        $entry = $entry->credit($this->accounts->get(ChartOfAccounts::CASH_CLEARING, $currency)->id, $refund->amount);

        return ($this->post)($entry->post(), new EntryMetadata(
            idempotencyKey: sprintf('refund:%s:succeeded', $refund->refundId),
            referenceType: self::REFUND_REFERENCE_TYPE,
            referenceId: $refund->refundId,
            postedAt: $refund->occurredAt,
        ));
    }

    /**
     * The tax this refund returns: the cumulative share after it, less the
     * cumulative share before it.
     */
    public static function taxShare(RefundIssued $refund, OrderSummary $order): Money
    {
        $total = $order->total->amount;

        if ($total <= 0 || ! $order->tax->isPositive()) {
            return Money::zero($refund->amount->currency);
        }

        $before = $refund->previouslyRefunded->amount;
        $after = $before + $refund->amount->amount;
        $share = intdiv($after * $order->tax->amount, $total) - intdiv($before * $order->tax->amount, $total);

        return Money::of($share, $refund->amount->currency);
    }
}
