<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Tenancy\Application\Queries\AllTenantIds;
use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * Nightly: forgets finished deliveries (succeeded or dead) older than the
 * outbox retention, matching the event log they belong to.
 */
final class PruneWebhookDeliveriesCommand extends Command
{
    protected $signature = 'webhooks:prune';

    protected $description = 'Delete finished webhook deliveries past their retention';

    public function handle(AllTenantIds $tenants, TenantContext $context, Clock $clock, Config $config): int
    {
        $cutoff = $clock->now()->subDays($config->integer('outbox.retention_days', 30))->utc()->format('Y-m-d H:i:s.uP');
        $deleted = 0;

        foreach ($tenants() as $tenantId) {
            $deleted += $context->runAs($tenantId, static fn (): int => DB::table('webhook_deliveries')
                ->whereIn('status', [DeliveryStatus::Succeeded->value, DeliveryStatus::Dead->value])
                ->where('updated_at', '<', $cutoff)
                ->delete());
        }

        $this->components->info(sprintf('%d webhook deliver(ies) pruned.', $deleted));

        return self::SUCCESS;
    }
}
