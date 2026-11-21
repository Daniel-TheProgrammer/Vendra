<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * Deliberately vague: never reveal whether the e-mail or the password was wrong.
 */
final class InvalidCredentials extends DomainException
{
    public static function create(): self
    {
        return new self('These credentials do not match our records.');
    }

    public function status(): int
    {
        return 401;
    }

    public function type(): string
    {
        return 'invalid-credentials';
    }

    public function title(): string
    {
        return 'Invalid credentials';
    }
}
