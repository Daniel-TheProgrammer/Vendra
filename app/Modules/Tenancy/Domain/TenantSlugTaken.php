<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class TenantSlugTaken extends ConflictException
{
    public static function for(string $slug): self
    {
        return new self(sprintf('A tenant with slug "%s" already exists.', $slug));
    }

    public function type(): string
    {
        return 'tenant-slug-taken';
    }
}
