<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use App\Shared\Domain\Exception\DomainException;

final class InvalidTenantSlug extends DomainException
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const MAX_LENGTH = 63;

    public static function for(string $slug): self
    {
        return new self(sprintf(
            '"%s" is not a valid slug: use 1-%d lower-case letters, digits and single hyphens.',
            $slug,
            self::MAX_LENGTH,
        ));
    }

    public function type(): string
    {
        return 'invalid-tenant-slug';
    }

    public function title(): string
    {
        return 'Invalid tenant slug';
    }
}
