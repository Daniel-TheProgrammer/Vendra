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
use App\Modules\Payments\Domain\Settlement;

/**
 * How money moving through the payment provider is booked (ADR 0008).
 *
 *   captured  Dr Cash / Stripe Clearing  amount - fee
 *             Dr Payment Fees            fee (when there is one)
 *               Cr Accounts Receivable     amount
 *                  (Customer Credits, when the order could no longer
 *                   take the money and it is owed back)
 *
 * Keys are "payment:{intent id}:captured": the provider's webhook and our
 * own capture response both raise PaymentCaptured, and it books once.
 */
final readonly class PaymentPostings
{
    public const REFERENCE_TYPE = 'payment';

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
}
