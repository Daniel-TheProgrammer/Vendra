<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Resources;

use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Ledger\Infrastructure\Models\JournalLineRecord;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class JournalEntryResource extends ApiResource
{
    public function __construct(JournalEntryRecord $entry)
    {
        parent::__construct($entry);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalEntryRecord $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'kind' => $entry->kind->value,
            'currency' => $entry->currency->value,
            'description' => $entry->description,
            'reason' => $entry->reason,
            'reference' => $entry->reference_type !== null ? ['type' => $entry->reference_type, 'id' => $entry->reference_id] : null,
            'reverses_entry_id' => $entry->reverses_entry_id,
            'reversed_by_entry_id' => $entry->reversedBy?->id,
            'lines' => $entry->lines->map(static fn (JournalLineRecord $line): array => [
                'position' => $line->position,
                'account' => ['id' => $line->account_id, 'code' => $line->account->code, 'name' => $line->account->name],
                'side' => $line->debit > 0 ? 'debit' : 'credit',
                'amount' => Money::of($line->debit + $line->credit, $line->currency)->toDecimal(),
                'amount_minor' => $line->debit + $line->credit,
                'memo' => $line->memo,
            ])->values()->all(),
            'actor_id' => $entry->actor_id,
            'posted_at' => $entry->posted_at->toIso8601String(),
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
