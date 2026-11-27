<?php

declare(strict_types=1);

namespace App\Modules\Webhooks;

use App\Modules\Webhooks\Application\Delivery\WebhookTransport;
use App\Modules\Webhooks\Application\Security\HostResolver;
use App\Modules\Webhooks\Console\DeliverDueWebhooksCommand;
use App\Modules\Webhooks\Console\PruneWebhookDeliveriesCommand;
use App\Modules\Webhooks\Infrastructure\Delivery\HttpWebhookTransport;
use App\Modules\Webhooks\Infrastructure\Listeners\FanOutToSubscriptions;
use App\Modules\Webhooks\Infrastructure\Security\DnsHostResolver;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;
use App\Shared\Infrastructure\Outbox\EventPublished;
use Illuminate\Support\Facades\Event;

final class WebhooksServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Webhooks';
    }

    protected function moduleCommands(): array
    {
        return [DeliverDueWebhooksCommand::class, PruneWebhookDeliveriesCommand::class];
    }

    public function register(): void
    {
        $this->app->singleton(HostResolver::class, DnsHostResolver::class);
        $this->app->singleton(WebhookTransport::class, HttpWebhookTransport::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(EventPublished::class, FanOutToSubscriptions::class);
    }
}
