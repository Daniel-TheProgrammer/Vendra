<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Listeners;

use App\Modules\Ledger\Application\Postings\OrderPostings;
use App\Modules\Orders\Application\Queries\FindOrderSummary;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\Events\OrderEvent;
use App\Modules\Orders\Domain\Events\OrderPaid;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Orders\Domain\Events\OrderRefunded;
use App\Shared\Tenancy\TenantContext;

/**
 * Books order events in the ledger, in the order's tenant. Runs in-process
 * today; with the outbox (Phase 7) the same listener is fed from it and
 * retried, which the postings' idempotency keys already allow for.
 */
final readonly class BookOrderLifecycle
{
    public function __construct(
        private TenantContext $tenant,
        private FindOrderSummary $orders,
        private OrderPostings $postings,
    ) {}

    public function handle(OrderEvent $event): void
    {
        $this->tenant->runAs($event->tenantId, function () use ($event): void {
            $order = ($this->orders)($event->orderId);

            match (true) {
                $event instanceof OrderPlaced => $this->postings->placed($order),
                $event instanceof OrderPaid => $this->postings->paid($order),
                $event instanceof OrderCancelled => $this->postings->cancelled($order),
                $event instanceof OrderRefunded => $this->postings->refunded($order),
                default => null,
            };
        });
    }
}
