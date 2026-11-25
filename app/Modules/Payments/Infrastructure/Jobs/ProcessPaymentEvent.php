<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Jobs;

use App\Modules\Payments\Infrastructure\Webhooks\StripeEventProcessor;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Processes one stored gateway event, in its tenant. Retried with backoff;
 * processing is idempotent, so a retry after a partial failure is safe. An
 * event that exhausts its retries stays unprocessed in payment_events, with
 * its last error, for payments:reconcile and a human.
 */
final class ProcessPaymentEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 600, 1800, 3600];

    public function __construct(
        public readonly string $eventId,
        public readonly string $tenantId,
    ) {}

    public function handle(TenantContext $tenant, StripeEventProcessor $process): void
    {
        $tenant->runAs($this->tenantId, fn () => $process($this->eventId));
    }

    public function failed(Throwable $error): void
    {
        app(LoggerInterface::class)->critical('A payment event could not be processed after every retry.', [
            'payment_event_id' => $this->eventId,
            'tenant_id' => $this->tenantId,
            'error' => $error->getMessage(),
        ]);
    }
}
