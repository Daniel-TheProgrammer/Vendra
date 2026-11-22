<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\ConflictException;

/**
 * A tenant must always keep at least one owner, or nobody could manage it.
 */
final class LastOwnerRequired extends ConflictException
{
    public static function create(): self
    {
        return new self('A tenant must keep at least one owner. Promote someone else first.');
    }

    public function type(): string
    {
        return 'last-owner-required';
    }
}
