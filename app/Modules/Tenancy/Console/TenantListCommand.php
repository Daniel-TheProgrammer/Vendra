<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Illuminate\Console\Command;

final class TenantListCommand extends Command
{
    protected $signature = 'tenant:list';

    protected $description = 'List all tenants';

    public function handle(): int
    {
        $rows = Tenant::query()
            ->orderBy('created_at')
            ->get()
            ->map(static fn (Tenant $tenant): array => [
                $tenant->id,
                $tenant->slug,
                $tenant->name,
                $tenant->plan->value,
                $tenant->status->value,
                $tenant->created_at?->toDateTimeString() ?? '',
            ])
            ->all();

        if ($rows === []) {
            $this->components->info('No tenants yet. Create one with tenant:create.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug', 'Name', 'Plan', 'Status', 'Created'], $rows);

        return self::SUCCESS;
    }
}
