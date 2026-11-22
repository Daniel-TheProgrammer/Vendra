<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

final class InvalidInvitation extends DomainException
{
    public static function create(): self
    {
        return new self('This invitation is invalid, has expired or was already used.');
    }

    public function type(): string
    {
        return 'invalid-invitation';
    }

    public function title(): string
    {
        return 'Invalid invitation';
    }
}
