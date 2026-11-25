<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

use App\Shared\Domain\Exception\ConflictException;

/**
 * The provider refused the request itself (not the card): capturing an
 * intent that was never authorised, cancelling one already captured.
 * Retrying the same request will not help.
 */
final class GatewayRejected extends ConflictException
{
    private string $gatewayCode = '';

    public static function because(string $gatewayCode, string $message): self
    {
        $exception = new self($message);
        $exception->gatewayCode = $gatewayCode;

        return $exception;
    }

    public function gatewayCode(): string
    {
        return $this->gatewayCode;
    }

    public function type(): string
    {
        return 'payment-gateway-rejected';
    }

    public function title(): string
    {
        return 'The payment provider refused the request';
    }

    /**
     * @return array{gateway_code: string}
     */
    public function extensions(): array
    {
        return ['gateway_code' => $this->gatewayCode];
    }
}
