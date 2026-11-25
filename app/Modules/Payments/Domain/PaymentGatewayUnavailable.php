<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\DomainException;
use Throwable;

/**
 * The gateway could not be reached, or failed on its side. The gateway call
 * carries an idempotency key, so the client can safely retry the request.
 */
final class PaymentGatewayUnavailable extends DomainException
{
    public static function because(string $detail, ?Throwable $previous = null): self
    {
        return new self(sprintf('The payment provider is unavailable (%s). Retry shortly.', $detail), 0, $previous);
    }

    public function status(): int
    {
        return 503;
    }

    public function type(): string
    {
        return 'payment-gateway-unavailable';
    }

    public function title(): string
    {
        return 'Payment provider unavailable';
    }

    public function headers(): array
    {
        return ['Retry-After' => '5'];
    }
}
