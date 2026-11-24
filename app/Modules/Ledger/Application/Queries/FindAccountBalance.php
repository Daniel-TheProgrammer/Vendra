<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Domain\AccountBalance;
use App\Modules\Ledger\Infrastructure\Models\AccountBalanceRecord;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Money;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * An account's balance now (from the materialised totals, one row read) or
 * as of an instant (summed from the lines of entries posted up to and
 * including it).
 */
final readonly class FindAccountBalance
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $accountId, ?DateTimeInterface $asOf = null): AccountBalance
    {
        $account = LedgerAccount::query()->whereKey($accountId)->first()
            ?? throw EntityNotFound::withId('Ledger account', $accountId);

        [$debits, $credits] = $asOf instanceof DateTimeInterface ? $this->summed($account, $asOf) : $this->current($account);

        return new AccountBalance(Money::of($debits, $account->currency), Money::of($credits, $account->currency), $account->normal_balance);
    }

    /**
     * @return array{int, int}
     */
    private function current(LedgerAccount $account): array
    {
        $balance = AccountBalanceRecord::query()->whereKey($account->id)->first();

        return [$balance->debit_total ?? 0, $balance->credit_total ?? 0];
    }

    /**
     * @return array{int, int}
     */
    private function summed(LedgerAccount $account, DateTimeInterface $asOf): array
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.posted_at', '<=', $asOf->format('Y-m-d H:i:s.uP'))
            ->selectRaw('coalesce(sum(journal_lines.debit), 0) AS debits, coalesce(sum(journal_lines.credit), 0) AS credits')
            ->first();

        return [(int) ($row->debits ?? 0), (int) ($row->credits ?? 0)];
    }
}
