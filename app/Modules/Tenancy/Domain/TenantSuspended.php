<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use App\Shared\Domain\Exception\DomainException;

final class TenantSuspended extends DomainException
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('Tenant "%s" is suspended.', $slug));
    }

    public function status(): int
    {
        return 403;
    }

    public function type(): string
    {
        return 'tenant-suspended';
    }

    public function title(): string
    {
        return 'Tenant suspended';
    }
}
