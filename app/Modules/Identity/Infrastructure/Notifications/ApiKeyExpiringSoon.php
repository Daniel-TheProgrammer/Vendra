<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ApiKeyExpiringSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $keyName,
        public readonly string $prefix,
        public readonly CarbonImmutable $expiresAt,
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
            ->subject(sprintf('API key "%s" expires on %s', $this->keyName, $this->expiresAt->toFormattedDateString()))
            ->line(sprintf('The API key "%s" (%s…) expires on %s UTC.', $this->keyName, $this->prefix, $this->expiresAt->toDayDateTimeString()))
            ->line('Rotate it before then so integrations using it keep working. After rotation the old key stays valid for a short grace period.');
    }
}
