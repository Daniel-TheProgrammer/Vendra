<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * What an API key may do. Keys are granted an explicit list; there is no
 * wildcard, so a leaked key can only ever do what it was created for.
 * New modules add their scopes here as they ship.
 */
enum ApiScope: string
{
    case TenantRead = 'tenant:read';
    case CatalogRead = 'catalog:read';
    case CatalogWrite = 'catalog:write';
    case OrdersRead = 'orders:read';
    case OrdersWrite = 'orders:write';
    case LedgerRead = 'ledger:read';
    case PaymentsWrite = 'payments:write';
    case WebhooksManage = 'webhooks:manage';
    case ReportsRead = 'reports:read';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
