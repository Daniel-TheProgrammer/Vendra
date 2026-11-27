<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Listeners;

use App\Modules\Webhooks\Application\Delivery\FanOutEvent;
use App\Modules\Webhooks\Infrastructure\Jobs\DeliverWebhook;
use App\Shared\Infrastructure\Outbox\EventPublished;

/**
 * Every published event, to every subscription that wants it. Runs in the
 * relay's savepoint and tenant; the first attempts are queued after the
 * relay commits, so a worker never looks for a delivery that is not there.
 */
final readonly class FanOutToSubscriptions
{
    public function __construct(
        private FanOutEvent $fanOut,
    ) {}

    public function handle(EventPublished $event): void
    {
        foreach (($this->fanOut)($event) as $deliveryId) {
            DeliverWebhook::dispatch($deliveryId, $event->tenantId)->afterCommit();
        }
    }
}
