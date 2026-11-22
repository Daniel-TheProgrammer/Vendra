<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * Sent to an address, not to a user: the invitee may not have an account yet.
 */
final class MemberInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $roleLabel,
        #[SensitiveParameter]
        public readonly string $token,
        public readonly string $acceptUrl,
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
            ->subject(sprintf('You have been invited to %s on Vendra', $this->tenantName))
            ->line(sprintf('You have been invited to join %s as %s.', $this->tenantName, $this->roleLabel))
            ->action('Accept invitation', $this->acceptUrl)
            ->line(sprintf('The invitation expires on %s UTC.', $this->expiresAt->toDayDateTimeString()));
    }
}
