<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Listeners;

use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Payments\Application\Actions\CancelOpenPayments;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Queued: cancelling an order must not wait on, or fail with, the payment
 * provider. A capture that slips in before this runs settles as customer
 * credit (PaymentSettlement), so the money is accounted for either way.
 */
final class ReleasePaymentsOfCancelledOrder implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CancelOpenPayments $cancel,
    ) {}

    public function handle(OrderCancelled $event): void
    {
        $this->tenant->runAs($event->tenantId, fn () => ($this->cancel)($event->orderId));
    }
}
