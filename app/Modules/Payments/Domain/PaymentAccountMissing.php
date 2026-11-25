<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class PaymentAccountMissing extends ConflictException
{
    public static function forTenant(): self
    {
        return new self('This tenant has not connected a payment account yet (PUT /payments/account).');
    }

    public function type(): string
    {
        return 'payment-account-missing';
    }

    public function title(): string
    {
        return 'No payment account';
    }
}
