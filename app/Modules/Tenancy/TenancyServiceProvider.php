<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Console\TenantCreateCommand;
use App\Modules\Tenancy\Console\TenantListCommand;
use App\Modules\Tenancy\Console\TenantRunCommand;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Tenancy';
    }

    protected function moduleEvents(): array
    {
        return [TenantCreated::class];
    }

    protected function moduleCommands(): array
    {
        return [
            TenantCreateCommand::class,
            TenantListCommand::class,
            TenantRunCommand::class,
        ];
    }
}
