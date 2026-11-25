<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Everything the API can authorise. Routes require these with the
 * "permission:<value>" middleware; RolePermissions decides who holds them.
 *
 * A permission that machine clients may hold maps to an API scope. One that
 * maps to none is for people only: no API key can ever hold it.
 */
enum Permission: string
{
    case TenantView = 'tenant.view';
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';
    case ApiKeysManage = 'api_keys.manage';
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case LedgerView = 'ledger.view';
    case LedgerPost = 'ledger.post';
    case PaymentsManage = 'payments.manage';
    case PaymentsConfigure = 'payments.configure';
    case WebhooksManage = 'webhooks.manage';
    case ReportsView = 'reports.view';

    public function scope(): ?ApiScope
    {
        return match ($this) {
            self::TenantView => ApiScope::TenantRead,
            self::CatalogView => ApiScope::CatalogRead,
            self::CatalogManage => ApiScope::CatalogWrite,
            self::OrdersView => ApiScope::OrdersRead,
            self::OrdersManage => ApiScope::OrdersWrite,
            self::LedgerView => ApiScope::LedgerRead,
            self::PaymentsManage => ApiScope::PaymentsWrite,
            self::WebhooksManage => ApiScope::WebhooksManage,
            self::ReportsView => ApiScope::ReportsRead,
            self::MembersView, self::MembersManage, self::ApiKeysManage, self::LedgerPost, self::PaymentsConfigure => null,
        };
    }
}
