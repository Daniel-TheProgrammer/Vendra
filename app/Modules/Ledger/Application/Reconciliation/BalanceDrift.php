<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Reconciliation;

/**
 * An account whose materialised totals disagree with its lines. Should never
 * exist; one found means a write bypassed PostJournalEntry.
 */
final readonly class BalanceDrift
{
    public function __construct(
        public string $tenantId,
        public string $accountId,
        public string $code,
        public string $currency,
        public int $storedDebits,
        public int $storedCredits,
        public int $actualDebits,
        public int $actualCredits,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
