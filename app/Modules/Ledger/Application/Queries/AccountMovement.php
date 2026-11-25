<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Application\Services\Accounts;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * How much an account moved (debits minus credits) through entries posted
 * since an instant, optionally only entries about given kinds of reference.
 * Used to compare the ledger with an outside record, such as the payment
 * provider's balance.
 */
final readonly class AccountMovement
{
    public function __construct(
        private Accounts $accounts,
    ) {}

    /**
     * @param  list<string>  $referenceTypes  empty for every entry
     */
    public function __invoke(string $code, Currency $currency, DateTimeInterface $since, array $referenceTypes = []): Money
    {
        $account = $this->accounts->get($code, $currency);

        $net = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.posted_at', '>=', $since->format('Y-m-d H:i:s.uP'))
            ->when($referenceTypes !== [], static fn ($query) => $query->whereIn('journal_entries.reference_type', $referenceTypes))
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));

        return Money::of((int) $net, $currency);
    }
}
