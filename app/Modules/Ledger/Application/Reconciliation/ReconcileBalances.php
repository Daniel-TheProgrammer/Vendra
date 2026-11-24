<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Reconciliation;

use Illuminate\Support\Facades\DB;

/**
 * Compares every account's materialised totals with the sum of its lines,
 * for the current tenant, in one query.
 */
final readonly class ReconcileBalances
{
    /**
     * @return list<BalanceDrift>
     */
    public function __invoke(): array
    {
        /** @var list<object{tenant_id: string, id: string, code: string, currency: string, stored_debits: int|string, stored_credits: int|string, actual_debits: int|string, actual_credits: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT a.tenant_id, a.id, a.code, a.currency,
                   coalesce(b.debit_total, 0) AS stored_debits, coalesce(b.credit_total, 0) AS stored_credits,
                   coalesce(l.debits, 0) AS actual_debits, coalesce(l.credits, 0) AS actual_credits
              FROM ledger_accounts a
              LEFT JOIN account_balances b ON b.account_id = a.id
              LEFT JOIN (SELECT account_id, sum(debit) AS debits, sum(credit) AS credits FROM journal_lines GROUP BY account_id) l
                     ON l.account_id = a.id
             WHERE coalesce(b.debit_total, 0) <> coalesce(l.debits, 0)
                OR coalesce(b.credit_total, 0) <> coalesce(l.credits, 0)
             ORDER BY a.code, a.currency
            SQL);

        return array_values(array_map(static fn (object $row): BalanceDrift => new BalanceDrift(
            tenantId: $row->tenant_id,
            accountId: $row->id,
            code: $row->code,
            currency: $row->currency,
            storedDebits: (int) $row->stored_debits,
            storedCredits: (int) $row->stored_credits,
            actualDebits: (int) $row->actual_debits,
            actualCredits: (int) $row->actual_credits,
        ), $rows));
    }
}
