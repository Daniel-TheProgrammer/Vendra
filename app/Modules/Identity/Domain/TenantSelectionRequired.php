<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The user belongs to several tenants and did not say which one to sign in to.
 * Raised only after the password was verified, so listing the tenants is safe.
 */
final class TenantSelectionRequired extends DomainException
{
    /**
     * @param  list<array{id: string, slug: string, name: string}>  $tenants
     */
    private function __construct(
        string $message,
        private readonly array $tenants,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<array{id: string, slug: string, name: string}>  $tenants
     */
    public static function among(array $tenants): self
    {
        return new self('You belong to several tenants; repeat the request with the "tenant" field.', $tenants);
    }

    public function type(): string
    {
        return 'tenant-selection-required';
    }

    public function title(): string
    {
        return 'Tenant selection required';
    }

    public function extensions(): array
    {
        return ['tenants' => $this->tenants];
    }
}
