<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Application\Actions\CreateTenant;
use App\Modules\Tenancy\Application\Commands\CreateTenantCommand;
use App\Modules\Tenancy\Domain\TenantPlan;
use App\Shared\Domain\Exception\DomainException;
use Illuminate\Console\Command;

final class TenantCreateCommand extends Command
{
    protected $signature = 'tenant:create
        {name : Display name of the tenant}
        {--slug= : URL-safe identifier; derived from the name when omitted}
        {--plan=free : Billing plan: free, pro or enterprise}';

    protected $description = 'Create a tenant';

    public function handle(CreateTenant $createTenant): int
    {
        $name = $this->argument('name');
        $slug = $this->option('slug');
        $planOption = $this->option('plan');

        if (! is_string($name) || trim($name) === '') {
            $this->components->error('A tenant name is required.');

            return self::INVALID;
        }

        $plan = is_string($planOption) ? TenantPlan::tryFrom($planOption) : null;

        if ($plan === null) {
            $this->components->error('Plan must be one of: '.implode(', ', array_column(TenantPlan::cases(), 'value')));

            return self::INVALID;
        }

        try {
            $tenant = $createTenant(new CreateTenantCommand(
                name: trim($name),
                slug: is_string($slug) && $slug !== '' ? $slug : null,
                plan: $plan,
            ));
        } catch (DomainException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Tenant "%s" created with id %s.', $tenant->slug, $tenant->id));

        return self::SUCCESS;
    }
}
