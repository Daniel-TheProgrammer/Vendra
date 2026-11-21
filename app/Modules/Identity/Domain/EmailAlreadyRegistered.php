<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class EmailAlreadyRegistered extends ConflictException
{
    public static function create(): self
    {
        return new self('An account with this e-mail address already exists. Sign in instead.');
    }

    public function type(): string
    {
        return 'email-already-registered';
    }
}
