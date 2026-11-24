<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Domain;

/**
 * The accounts every tenant has. Each exists once per currency the tenant
 * trades in: "1200 Accounts Receivable" in USD and in EUR are two accounts,
 * because amounts in different currencies cannot be added up.
 *
 * Codes are strings that happen to be digits; they are never used as array
 * keys, where PHP would silently turn them into integers.
 */
final class ChartOfAccounts
{
    public const CASH_CLEARING = '1010';

    public const ACCOUNTS_RECEIVABLE = '1200';

    public const TAX_PAYABLE = '2100';

    public const CUSTOMER_CREDITS = '2200';

    public const OWNER_EQUITY = '3000';

    public const SALES_REVENUE = '4000';

    public const PAYMENT_FEES = '5100';

    public const REFUNDS = '5200';

    /**
     * @return list<array{code: string, name: string, type: AccountType}> in code order
     */
    public static function definitions(): array
    {
        return [
            ['code' => self::CASH_CLEARING, 'name' => 'Cash / Stripe Clearing', 'type' => AccountType::Asset],
            ['code' => self::ACCOUNTS_RECEIVABLE, 'name' => 'Accounts Receivable', 'type' => AccountType::Asset],
            ['code' => self::TAX_PAYABLE, 'name' => 'Tax Payable', 'type' => AccountType::Liability],
            ['code' => self::CUSTOMER_CREDITS, 'name' => 'Customer Credits', 'type' => AccountType::Liability],
            ['code' => self::OWNER_EQUITY, 'name' => 'Owner Equity', 'type' => AccountType::Equity],
            ['code' => self::SALES_REVENUE, 'name' => 'Sales Revenue', 'type' => AccountType::Revenue],
            ['code' => self::PAYMENT_FEES, 'name' => 'Payment Fees', 'type' => AccountType::Expense],
            ['code' => self::REFUNDS, 'name' => 'Refunds', 'type' => AccountType::Expense],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (array $definition): string => $definition['code'], self::definitions());
    }

    /**
     * @return array{code: string, name: string, type: AccountType}|null
     */
    public static function definition(string $code): ?array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['code'] === $code) {
                return $definition;
            }
        }

        return null;
    }
}
