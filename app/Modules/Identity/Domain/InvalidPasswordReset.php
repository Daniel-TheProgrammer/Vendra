<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

final class InvalidPasswordReset extends DomainException
{
    public static function create(): self
    {
        return new self('This password reset link is invalid or has expired.');
    }

    public function type(): string
    {
        return 'invalid-password-reset';
    }

    public function title(): string
    {
        return 'Invalid password reset';
    }
}
