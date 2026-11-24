<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Postings;

use App\Modules\Ledger\Application\Actions\PostJournalEntry;
use App\Modules\Ledger\Application\Actions\ReverseJournalEntry;
use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Orders\Application\Queries\OrderSummary;
use App\Shared\Application\Transaction\TransactionRunner;
use DateTimeInterface;

/**
 * How the order lifecycle is booked. Every posting has a deterministic key
 * ("order:{id}:placed"), so delivering the same event twice books it once.
 *
 *   placed    Dr Accounts Receivable  total
 *               Cr Sales Revenue        subtotal
 *               Cr Tax Payable          tax (when there is any)
 *   paid      Dr Cash / Stripe Clearing  total
 *               Cr Accounts Receivable     total
 *   cancelled the "placed" entry is reversed (nothing was paid)
 *   refunded  the "placed" and "paid" entries are reversed: revenue, tax,
 *             receivable and cash all return to where they were
 *
 * Payment fees are booked by the Payments module (Phase 6), which knows them.
 */
final readonly class OrderPostings
{
    public const REFERENCE_TYPE = 'order';

    public function __construct(
        private TransactionRunner $transaction,
        private Accounts $accounts,
        private PostJournalEntry $post,
        private ReverseJournalEntry $reverse,
    ) {}

    /**
     * Null for a free order: there is nothing to book.
     */
    public function placed(OrderSummary $order): ?JournalEntryRecord
    {
        if (! $order->total->isPositive()) {
            return null;
        }

        $currency = $order->total->currency;
        $entry = JournalEntry::draft($currency, sprintf('Order %d placed', $order->number))
            ->debit($this->accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, $currency)->id, $order->total);

        // Either part may be zero (no tax configured, or only free items
        // taxed); a journal line is never zero, so each credit is conditional.
        if ($order->subtotal->isPositive()) {
            $entry = $entry->credit($this->accounts->get(ChartOfAccounts::SALES_REVENUE, $currency)->id, $order->subtotal);
        }

        if ($order->tax->isPositive()) {
            $entry = $entry->credit($this->accounts->get(ChartOfAccounts::TAX_PAYABLE, $currency)->id, $order->tax);
        }

        return ($this->post)($entry->post(), $this->meta($order, 'placed', $order->placedAt));
    }

    public function paid(OrderSummary $order): ?JournalEntryRecord
    {
        if (! $order->total->isPositive()) {
            return null;
        }

        $currency = $order->total->currency;
        $entry = JournalEntry::draft($currency, sprintf('Order %d paid', $order->number))
            ->debit($this->accounts->get(ChartOfAccounts::CASH_CLEARING, $currency)->id, $order->total)
            ->credit($this->accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, $currency)->id, $order->total);

        return ($this->post)($entry->post(), $this->meta($order, 'paid'));
    }

    public function cancelled(OrderSummary $order): void
    {
        $this->transaction->run(fn () => $this->reverseIfPosted($order, 'placed', 'Order cancelled'));
    }

    public function refunded(OrderSummary $order): void
    {
        $this->transaction->run(function () use ($order): void {
            $this->reverseIfPosted($order, 'paid', 'Order refunded');
            $this->reverseIfPosted($order, 'placed', 'Order refunded');
        });
    }

    /**
     * Reversing is idempotent too: an entry already reversed is left alone,
     * and one never posted has nothing to undo.
     */
    private function reverseIfPosted(OrderSummary $order, string $stage, string $reason): void
    {
        $entry = JournalEntryRecord::query()->where('idempotency_key', self::key($order, $stage))->first();

        if (! $entry instanceof JournalEntryRecord || JournalEntryRecord::query()->where('reverses_entry_id', $entry->id)->exists()) {
            return;
        }

        ($this->reverse)($entry->id, $reason);
    }

    private function meta(OrderSummary $order, string $stage, ?DateTimeInterface $at = null): EntryMetadata
    {
        return new EntryMetadata(
            idempotencyKey: self::key($order, $stage),
            referenceType: self::REFERENCE_TYPE,
            referenceId: $order->id,
            postedAt: $at,
        );
    }

    public static function key(OrderSummary $order, string $stage): string
    {
        return sprintf('order:%s:%s', $order->id, $stage);
    }
}
