<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Listeners;

use App\Modules\Ledger\Application\Postings\OrderPostings;
use App\Modules\Orders\Application\Queries\FindOrderSummary;
use App\Modules\Orders\Domain\Events\OrderEvent;
use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Tenancy\TenantContext;

/**
 * Books an order in the ledger from its current state, in its tenant.
 *
 * Events come from the outbox at least once and in no guaranteed order
 * (ADR 0010), so the listener does not trust the event's kind: whichever
 * order event arrives, the sale is booked (once, by key) and, if the order
 * is cancelled by now, reversed (once). An OrderCancelled delivered before
 * its OrderPlaced therefore still ends with the sale booked and reversed.
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

            $this->postings->placed($order);

            if ($order->status === OrderStatus::Cancelled) {
                $this->postings->cancelled($order);
            }
        });
    }
}
