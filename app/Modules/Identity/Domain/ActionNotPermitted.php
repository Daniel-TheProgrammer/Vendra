<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The principal is authenticated but may not perform this action, either
 * because of its role or because an API key lacks the scope.
 */
final class ActionNotPermitted extends DomainException
{
    public static function missingScope(ApiScope $scope): self
    {
        return new self(sprintf('This API key does not have the "%s" scope.', $scope->value));
    }

    public static function forRole(MembershipRole $role, string $action): self
    {
        return new self(sprintf('The %s role may not %s.', str_replace('_', '-', $role->value), $action));
    }

    public static function forApiKey(string $action): self
    {
        return new self(sprintf('API keys may not %s; use a personal access token.', $action));
    }

    public function status(): int
    {
        return 403;
    }

    public function type(): string
    {
        return 'action-not-permitted';
    }

    public function title(): string
    {
        return 'Action not permitted';
    }
}
