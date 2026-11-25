<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * An inbound webhook we will not accept. Deliberately vague: an unknown
 * account and a bad signature read the same, so the endpoint cannot be used
 * to discover which accounts are connected.
 */
final class WebhookRejected extends DomainException
{
    public static function unverifiable(): self
    {
        return new self('The webhook could not be verified.');
    }

    public static function malformed(string $detail): self
    {
        return new self('The webhook body is not a gateway event: '.$detail.'.');
    }

    public function status(): int
    {
        return 400;
    }

    public function type(): string
    {
        return 'webhook-rejected';
    }

    public function title(): string
    {
        return 'Webhook rejected';
    }
}
