<?php

declare(strict_types=1);

namespace App\Modules\Webhooks;

use App\Modules\Webhooks\Application\Security\HostResolver;
use App\Modules\Webhooks\Infrastructure\Security\DnsHostResolver;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;

final class WebhooksServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Webhooks';
    }

    public function register(): void
    {
        $this->app->singleton(HostResolver::class, DnsHostResolver::class);
    }
}
