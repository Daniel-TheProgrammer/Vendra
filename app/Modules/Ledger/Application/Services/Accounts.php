<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Services;

use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Infrastructure\Models\AccountBalanceRecord;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves chart-of-accounts codes to the tenant's account in a currency,
 * opening it (with its balance row) the first time that currency is used.
 */
final readonly class Accounts
{
    public function __construct(
        private TenantContext $tenant,
        private Clock $clock,
    ) {}

    public function get(string $code, Currency $currency): LedgerAccount
    {
        $existing = LedgerAccount::query()->where('code', $code)->where('currency', $currency->value)->first();

        if ($existing instanceof LedgerAccount) {
            return $existing;
        }

        $this->open([$code], $currency);

        return LedgerAccount::query()->where('code', $code)->where('currency', $currency->value)->firstOrFail();
    }

    /**
     * Opens the whole default chart in one currency; accounts that exist are
     * left alone.
     */
    public function seed(Currency $currency): void
    {
        $this->open(ChartOfAccounts::codes(), $currency);
    }

    /**
     * ON CONFLICT DO NOTHING, so concurrent first uses of a currency both
     * succeed and end up with the one account.
     *
     * @param  list<string>  $codes
     */
    private function open(array $codes, Currency $currency): void
    {
        $now = $this->clock->now();
        $tenantId = $this->tenant->id();
        $accounts = [];

        foreach ($codes as $code) {
            $definition = ChartOfAccounts::definition($code) ?? throw new InvalidArgumentException(sprintf('"%s" is not in the chart of accounts.', $code));

            $accounts[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $definition['name'],
                'type' => $definition['type']->value,
                'normal_balance' => $definition['type']->normalBalance()->value,
                'currency' => $currency->value,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        LedgerAccount::query()->insertOrIgnore($accounts);

        // Balance rows for whichever accounts now exist without one.
        $missing = LedgerAccount::query()
            ->whereIn('code', $codes)
            ->where('currency', $currency->value)
            ->whereDoesntHave('balance')
            ->pluck('id');

        AccountBalanceRecord::query()->insertOrIgnore($missing->map(static fn (string $accountId): array => [
            'account_id' => $accountId,
            'tenant_id' => $tenantId,
            'debit_total' => 0,
            'credit_total' => 0,
            'version' => 0,
            'updated_at' => $now,
        ])->all());
    }
}
