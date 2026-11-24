<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Application\Commands;

use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;

/**
 * An adjustment a person posts by hand: an opening balance, a correction a
 * reversal cannot express, a write-off. Always with a reason.
 */
final readonly class ManualEntryCommand
{
    /**
     * @param  non-empty-list<array{account_id: string, side: Side, amount: Money, memo: string|null}>  $lines
     */
    public function __construct(
        public Currency $currency,
        public string $description,
        public string $reason,
        public array $lines,
        public ?string $actorId,
    ) {}
}
