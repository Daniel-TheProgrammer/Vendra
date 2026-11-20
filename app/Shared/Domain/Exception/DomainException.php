<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Base class for business-rule failures.
 *
 * Subclasses describe themselves in HTTP Problem Details terms so the API layer
 * can render them without a mapping table. The message is safe to show to API
 * consumers; never put internal details in it.
 */
abstract class DomainException extends \DomainException
{
    public function status(): int
    {
        return 422;
    }

    /**
     * Stable, kebab-case identifier used as the problem "type" suffix.
     */
    public function type(): string
    {
        return 'domain-rule-violation';
    }

    public function title(): string
    {
        return 'Domain rule violated';
    }
}
