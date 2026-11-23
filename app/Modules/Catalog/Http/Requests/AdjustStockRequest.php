<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Domain\AdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * POST /variants/{variant}/stock-adjustments
 * { "quantity": 24, "reason": "received", "note": "PO-1042" }
 * A negative quantity writes stock off.
 */
final class AdjustStockRequest extends FormRequest
{
    public const MAX_QUANTITY = 1_000_000;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'not_in:0', 'between:-'.self::MAX_QUANTITY.','.self::MAX_QUANTITY],
            'reason' => ['required', 'string', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toCommand(string $variantId, ?string $actorId): AdjustStockCommand
    {
        return new AdjustStockCommand(
            variantId: $variantId,
            delta: $this->integer('quantity'),
            reason: AdjustmentReason::from($this->string('reason')->value()),
            note: $this->filled('note') ? $this->string('note')->trim()->value() : null,
            actorId: $actorId,
        );
    }
}
