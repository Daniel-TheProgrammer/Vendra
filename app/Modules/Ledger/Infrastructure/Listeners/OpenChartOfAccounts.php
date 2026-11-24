<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Listeners;

use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Gives every new tenant the default chart of accounts in the platform's
 * default currency. Other currencies open lazily on first posting.
 */
final readonly class OpenChartOfAccounts
{
    public function __construct(
        private TenantContext $context,
        private Accounts $accounts,
        private Config $config,
    ) {}

    public function handle(TenantCreated $event): void
    {
        $currency = Currency::from($this->config->string('ledger.default_currency', 'USD'));

        $this->context->runAs($event->tenantId, fn () => $this->accounts->seed($currency));
    }
}
