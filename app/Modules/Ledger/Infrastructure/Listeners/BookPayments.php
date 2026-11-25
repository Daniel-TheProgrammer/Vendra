<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Listeners;

use App\Modules\Ledger\Application\Postings\PaymentPostings;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Orders\Application\Queries\FindOrderSummary;
use App\Modules\Payments\Domain\Events\PaymentCaptured;
use App\Shared\Tenancy\TenantContext;

/**
 * Books money moving through the payment provider, in the payment's tenant.
 */
final readonly class BookPayments
{
    public function __construct(
        private TenantContext $tenant,
        private FindOrderSummary $orders,
        private PaymentPostings $postings,
    ) {}

    public function handle(PaymentCaptured $event): void
    {
        $this->tenant->runAs($event->tenantId, fn (): JournalEntryRecord => $this->postings->captured($event, ($this->orders)($event->orderId)));
    }
}
