<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;

/**
 * Runs another Artisan command with the tenant context set, so tenant-scoped
 * models and jobs behave exactly as they do inside a request:
 *
 *   php artisan tenant:run acme catalog:reindex -- --chunk=500
 */
final class TenantRunCommand extends Command
{
    // "command" is reserved by the console component for the command's own name.
    protected $signature = 'tenant:run
        {tenant : Tenant id or slug}
        {artisan-command : Artisan command to run as that tenant}
        {args?* : Arguments and options for that command; put them after --}';

    protected $description = 'Run an Artisan command in the context of one tenant';

    public function handle(FindTenant $findTenant, TenantContext $context, Kernel $artisan): int
    {
        $identifier = $this->argument('tenant');
        $command = $this->argument('artisan-command');
        $args = $this->argument('args');

        if (! is_string($identifier) || ! is_string($command)) {
            $this->components->error('Both a tenant and a command are required.');

            return self::INVALID;
        }

        $tenant = $findTenant($identifier);

        if (! $tenant instanceof TenantSnapshot) {
            $this->components->error(sprintf('Tenant "%s" not found.', $identifier));

            return self::FAILURE;
        }

        $commandLine = trim($command.' '.implode(' ', is_array($args) ? array_map(strval(...), $args) : []));

        return $context->runAs(
            $tenant->id,
            fn (): int => $artisan->call($commandLine, [], $this->output),
        );
    }
}
