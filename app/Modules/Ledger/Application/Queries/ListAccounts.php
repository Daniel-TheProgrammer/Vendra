<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Queries;

use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tenant's whole chart, every currency, with current balances. A chart
 * is a few dozen accounts at most, so it is not paginated.
 */
final readonly class ListAccounts
{
    /**
     * @return Collection<int, LedgerAccount>
     */
    public function __invoke(): Collection
    {
        return LedgerAccount::query()->with('balance')->orderBy('currency')->orderBy('code')->get();
    }
}
