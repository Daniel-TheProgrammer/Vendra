<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubscriptionPaused extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $url,
        public readonly int $failures,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A webhook subscription was paused')
            ->line(sprintf('Vendra paused the webhook subscription to %s after %d consecutive failed deliveries.', $this->url, $this->failures))
            ->line('Events are still recorded for it; nothing is sent until it is resumed.')
            ->line(sprintf('Fix the receiver, then resume it: PATCH /api/v1/webhooks/subscriptions/%s {"status": "active"}. Deliveries that died meanwhile can be sent again with POST /api/v1/webhooks/deliveries/{id}/redeliver.', $this->subscriptionId));
    }
}
