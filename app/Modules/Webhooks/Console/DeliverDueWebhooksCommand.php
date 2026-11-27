<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Tenancy\Application\Queries\AllTenantIds;
use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Domain\SubscriptionStatus;
use App\Modules\Webhooks\Infrastructure\Jobs\DeliverWebhook;
use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every minute: queues an attempt for each delivery whose time has come
 * (retries, and first attempts whose job was lost), of active
 * subscriptions only. Attempts claim their delivery, so queueing one twice
 * is harmless.
 */
final class DeliverDueWebhooksCommand extends Command
{
    protected $signature = 'webhooks:deliver-due {--limit=1000 : Deliveries per tenant per run}';

    protected $description = 'Queue the webhook deliveries that are due';

    public function handle(AllTenantIds $tenants, TenantContext $context, Clock $clock): int
    {
        $now = $clock->now()->utc()->format('Y-m-d H:i:s.uP');
        $queued = 0;

        foreach ($tenants() as $tenantId) {
            $queued += $context->runAs($tenantId, function () use ($tenantId, $now): int {
                $due = DB::table('webhook_deliveries')
                    ->join('webhook_subscriptions', 'webhook_subscriptions.id', '=', 'webhook_deliveries.subscription_id')
                    ->where('webhook_subscriptions.status', SubscriptionStatus::Active->value)
                    ->whereIn('webhook_deliveries.status', DeliveryStatus::dueValues())
                    ->where('webhook_deliveries.next_attempt_at', '<=', $now)
                    ->orderBy('webhook_deliveries.next_attempt_at')
                    ->limit(max(1, (int) $this->option('limit')))
                    ->pluck('webhook_deliveries.id');

                foreach ($due as $id) {
                    DeliverWebhook::dispatch((string) $id, $tenantId);
                }

                return $due->count();
            });
        }

        $this->components->info(sprintf('%d webhook deliver(ies) queued.', $queued));

        return self::SUCCESS;
    }
}
