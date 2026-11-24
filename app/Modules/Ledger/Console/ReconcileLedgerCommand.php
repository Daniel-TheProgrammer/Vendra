<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Console;

use App\Modules\Ledger\Application\Reconciliation\BalanceDrift;
use App\Modules\Ledger\Application\Reconciliation\ReconcileBalances;
use App\Modules\Tenancy\Application\Queries\AllTenantIds;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;

/**
 * Nightly proof that the materialised balances still equal the ledger.
 * Every drift is logged at critical level (which alerts) and the command
 * fails, so the scheduler reports it too. Read-only: it never "fixes" a
 * balance, because a drift means something wrote around the ledger and must
 * be investigated first.
 */
final class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Check every account balance against the sum of its journal lines';

    public function handle(AllTenantIds $tenants, TenantContext $context, ReconcileBalances $reconcile, LoggerInterface $log): int
    {
        $drifts = 0;
        $checked = 0;

        foreach ($tenants() as $tenantId) {
            $checked++;

            foreach ($context->runAs($tenantId, $reconcile) as $drift) {
                /** @var BalanceDrift $drift */
                $drifts++;
                $log->critical('Ledger balance drift', $drift->toArray());
                $this->components->error(sprintf('Tenant %s, account %s %s: stored %d/%d, lines %d/%d (debit/credit).', $drift->tenantId, $drift->code, $drift->currency, $drift->storedDebits, $drift->storedCredits, $drift->actualDebits, $drift->actualCredits));
            }
        }

        if ($drifts > 0) {
            return self::FAILURE;
        }

        $log->info('Ledger reconciled', ['tenants' => $checked, 'drift' => 0]);
        $this->components->info(sprintf('%d tenant(s) reconciled; zero drift.', $checked));

        return self::SUCCESS;
    }
}
