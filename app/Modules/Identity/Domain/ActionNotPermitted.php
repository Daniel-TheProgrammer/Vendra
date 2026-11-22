<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * The principal is authenticated but may not perform this action, because
 * of its role, because an API key lacks the scope, or because the action is
 * reserved for people.
 */
final class ActionNotPermitted extends DomainException
{
    public static function missingScope(ApiScope $scope): self
    {
        return new self(sprintf('This API key does not have the "%s" scope.', $scope->value));
    }

    public static function missingPermission(MembershipRole $role, Permission $permission): self
    {
        return new self(sprintf('The %s role does not have the "%s" permission.', self::roleName($role), $permission->value));
    }

    public static function forRole(MembershipRole $role, string $action): self
    {
        return new self(sprintf('The %s role may not %s.', self::roleName($role), $action));
    }

    public static function peopleOnly(Permission $permission): self
    {
        return new self(sprintf('"%s" is reserved for people; API keys cannot hold it.', $permission->value));
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

    private static function roleName(MembershipRole $role): string
    {
        return str_replace('_', '-', $role->value);
    }
}
