<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Identity\Application\Queries\TenantOwnerEmails;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Modules\Webhooks\Infrastructure\Notifications\SubscriptionPaused;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the tenant's owners that a subscription stopped receiving events,
 * because their integration is now missing them.
 */
final readonly class SubscriptionAlerts
{
    public function __construct(
        private TenantOwnerEmails $owners,
    ) {}

    public function paused(WebhookSubscription $subscription): void
    {
        $emails = ($this->owners)($subscription->tenant_id);

        if ($emails === []) {
            return;
        }

        Notification::route('mail', $emails)->notify(new SubscriptionPaused($subscription->id, $subscription->url, $subscription->failure_count));
    }
}
