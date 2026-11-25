<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests\Unit;

use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\RolePermissions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PermissionMatrixTest extends TestCase
{
    /**
     * Columns of the table in docs/permissions.md, in order.
     */
    private const ROLE_COLUMNS = [MembershipRole::Owner, MembershipRole::Admin, MembershipRole::Staff, MembershipRole::ReadOnly];

    #[Test]
    public function the_documented_matrix_matches_the_code(): void
    {
        $rows = $this->documentedRows();

        self::assertSame(
            array_map(static fn (Permission $p): string => $p->value, Permission::cases()),
            array_keys($rows),
            'docs/permissions.md must list every permission, in enum order',
        );

        foreach (Permission::cases() as $permission) {
            [$grants, $scope] = $rows[$permission->value];

            foreach (self::ROLE_COLUMNS as $index => $role) {
                self::assertSame(
                    RolePermissions::grants($role, $permission),
                    $grants[$index],
                    sprintf('%s / %s differs between docs and RolePermissions', $permission->value, $role->value),
                );
            }

            self::assertSame($permission->scope() instanceof ApiScope ? $permission->scope()->value : 'people only', $scope, $permission->value.' scope differs');
        }
    }

    #[Test]
    public function owners_hold_every_permission_and_roles_are_strictly_nested(): void
    {
        self::assertSame(Permission::cases(), RolePermissions::for(MembershipRole::Owner));

        $byRank = [MembershipRole::ReadOnly, MembershipRole::Staff, MembershipRole::Admin, MembershipRole::Owner];

        for ($i = 1, $n = count($byRank); $i < $n; $i++) {
            $lower = RolePermissions::for($byRank[$i - 1]);
            $higher = RolePermissions::for($byRank[$i]);

            self::assertSame([], array_udiff($lower, $higher, static fn (Permission $a, Permission $b): int => strcmp($a->value, $b->value)));
            self::assertGreaterThan(RolePermissions::rank($byRank[$i - 1]), RolePermissions::rank($byRank[$i]));
        }
    }

    #[Test]
    public function people_only_permissions_have_no_scope(): void
    {
        foreach ([Permission::MembersManage, Permission::ApiKeysManage, Permission::LedgerPost, Permission::PaymentsConfigure] as $permission) {
            self::assertNull($permission->scope(), $permission->value.' must never be grantable to an API key');
        }
    }

    /**
     * @return array<string, array{list<bool>, string}>
     */
    private function documentedRows(): array
    {
        $markdown = file_get_contents(dirname(__DIR__, 5).'/docs/permissions.md');
        self::assertIsString($markdown);

        $rows = [];

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\|\s*`([a-z_.]+)`\s*\|(.*)\|\s*$/u', $line, $match) !== 1) {
                continue;
            }

            $cells = array_map(trim(...), explode('|', $match[2]));
            $scope = trim(array_pop($cells), '` ');

            $rows[$match[1]] = [array_map(static fn (string $cell): bool => $cell === '✓', array_values($cells)), $scope];
        }

        return $rows;
    }
}
