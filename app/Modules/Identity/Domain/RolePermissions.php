<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The permission matrix. Changing it changes who can do what in every
 * tenant, so it lives in code and goes through review; docs/permissions.md
 * mirrors it and a test keeps the two from drifting apart.
 */
final class RolePermissions
{
    /**
     * @return list<Permission>
     */
    public static function for(MembershipRole $role): array
    {
        return match ($role) {
            MembershipRole::Owner => Permission::cases(),

            // Everything except posting manual ledger entries.
            MembershipRole::Admin => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $permission): bool => $permission !== Permission::LedgerPost,
            )),

            // Runs the shop day to day; no team, key, payment or webhook administration.
            MembershipRole::Staff => [
                Permission::TenantView,
                Permission::MembersView,
                Permission::CatalogView,
                Permission::CatalogManage,
                Permission::OrdersView,
                Permission::OrdersManage,
                Permission::LedgerView,
                Permission::ReportsView,
            ],

            MembershipRole::ReadOnly => [
                Permission::TenantView,
                Permission::MembersView,
                Permission::CatalogView,
                Permission::OrdersView,
                Permission::LedgerView,
                Permission::ReportsView,
            ],
        };
    }

    public static function grants(MembershipRole $role, Permission $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }

    /**
     * Rank used for "may this actor manage that member" decisions.
     */
    public static function rank(MembershipRole $role): int
    {
        return match ($role) {
            MembershipRole::Owner => 4,
            MembershipRole::Admin => 3,
            MembershipRole::Staff => 2,
            MembershipRole::ReadOnly => 1,
        };
    }
}
