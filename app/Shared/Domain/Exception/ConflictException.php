<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * The request is valid but conflicts with the current state of the resource
 * (stale version, duplicate key, illegal state transition).
 */
class ConflictException extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function type(): string
    {
        return 'conflict';
    }

    public function title(): string
    {
        return 'Conflict';
    }
}
