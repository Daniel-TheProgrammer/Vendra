<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class AlreadyAMember extends ConflictException
{
    public static function create(): self
    {
        return new self('This person is already a member of the tenant.');
    }

    public function type(): string
    {
        return 'already-a-member';
    }
}
