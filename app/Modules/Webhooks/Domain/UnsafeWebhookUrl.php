<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * A webhook URL Vendra will not deliver to. The reason is safe to show: it
 * says what is wrong with the URL the tenant gave, never what is on our
 * network.
 */
final class UnsafeWebhookUrl extends DomainException
{
    private string $reason = '';

    public static function because(string $reason, string $message): self
    {
        $exception = new self($message);
        $exception->reason = $reason;

        return $exception;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function type(): string
    {
        return 'unsafe-webhook-url';
    }

    public function title(): string
    {
        return 'Webhook URL not allowed';
    }

    /**
     * @return array{reason: string}
     */
    public function extensions(): array
    {
        return ['reason' => $this->reason];
    }
}
