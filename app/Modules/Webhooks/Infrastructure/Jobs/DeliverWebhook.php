<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Jobs;

use App\Modules\Webhooks\Application\Delivery\AttemptDelivery;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One attempt at one delivery. Never retried by the queue: the retry
 * schedule lives on the delivery (next_attempt_at), and webhooks:deliver-due
 * dispatches what is due, so a lost job only delays an attempt.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $deliveryId,
        public readonly string $tenantId,
    ) {}

    public function handle(TenantContext $tenant, AttemptDelivery $attempt): void
    {
        $tenant->runAs($this->tenantId, fn (): ?WebhookDelivery => $attempt($this->deliveryId));
    }
}
