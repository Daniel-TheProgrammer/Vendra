<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Domain\AccountType;
use App\Modules\Ledger\Domain\Side;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Every account's debit and credit totals, per currency, now (from the
 * materialised balances) or as of an instant (from the lines). In any
 * currency the debits column and the credits column always add up to the
 * same figure; the result says so explicitly.
 */
final readonly class TrialBalance
{
    /**
     * One section per currency, keyed by currency code.
     *
     * @return array<string, array{accounts: list<array{id: string, code: string, name: string, type: AccountType, normal_balance: Side, debits: int, credits: int}>, total_debits: int, total_credits: int, balanced: bool}>
     */
    public function __invoke(?DateTimeInterface $asOf = null): array
    {
        $totals = $asOf instanceof DateTimeInterface
            ? DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
                ->where('journal_entries.posted_at', '<=', $asOf->format('Y-m-d H:i:s.uP'))
                ->groupBy('journal_lines.account_id')
                ->selectRaw('journal_lines.account_id, sum(journal_lines.debit) AS debits, sum(journal_lines.credit) AS credits')
            : DB::table('account_balances')->select('account_id', 'debit_total AS debits', 'credit_total AS credits');

        /** @var list<object{id: string, code: string, name: string, type: string, normal_balance: string, currency: string, debits: int|string|null, credits: int|string|null}> $rows */
        $rows = DB::table('ledger_accounts')
            ->leftJoinSub($totals, 'totals', 'totals.account_id', '=', 'ledger_accounts.id')
            ->orderBy('ledger_accounts.currency')
            ->orderBy('ledger_accounts.code')
            ->get(['ledger_accounts.id', 'code', 'name', 'type', 'normal_balance', 'currency', 'totals.debits', 'totals.credits'])
            ->all();

        $report = [];

        foreach ($rows as $row) {
            $report[$row->currency] ??= ['accounts' => [], 'total_debits' => 0, 'total_credits' => 0, 'balanced' => true];
            $debits = (int) $row->debits;
            $credits = (int) $row->credits;

            $report[$row->currency]['accounts'][] = [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => AccountType::from($row->type),
                'normal_balance' => Side::from($row->normal_balance),
                'debits' => $debits,
                'credits' => $credits,
            ];
            $report[$row->currency]['total_debits'] += $debits;
            $report[$row->currency]['total_credits'] += $credits;
        }

        foreach ($report as $currency => $section) {
            $report[$currency]['balanced'] = $section['total_debits'] === $section['total_credits'];
        }

        return $report;
    }
}
