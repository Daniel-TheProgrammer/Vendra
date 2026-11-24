<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Application\Commands\ManualEntryCommand;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * POST /ledger/entries
 *
 *   { "currency": "USD", "description": "Opening cash float", "reason": "Till opened with 200.00",
 *     "lines": [ { "account_id": "…", "side": "debit", "amount": "200.00" },
 *                { "account_id": "…", "side": "credit", "amount": "200.00", "memo": "Owner contribution" } ] }
 *
 * Amounts are decimal strings in major units, as everywhere in the API.
 */
final class ManualEntryRequest extends FormRequest
{
    public const MAX_LINES = 50;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'description' => ['required', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:500'],
            'lines' => ['required', 'array', 'list', 'min:2', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.side' => ['required', 'string', Rule::enum(Side::class)],
            'lines.*.amount' => ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,3})?$/'],
            'lines.*.memo' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $currency = Currency::tryFrom($this->string('currency')->value());

                if (! $currency instanceof Currency) {
                    return;
                }

                foreach ((array) $this->input('lines', []) as $index => $line) {
                    $amount = is_array($line) ? ($line['amount'] ?? null) : null;

                    if (! is_string($amount)) {
                        continue;
                    }

                    try {
                        if (! Money::fromDecimal($amount, $currency)->isPositive()) {
                            $validator->errors()->add('lines.'.$index.'.amount', 'The amount must be greater than zero.');
                        }
                    } catch (InvalidArgumentException) {
                        $validator->errors()->add('lines.'.$index.'.amount', sprintf('%s amounts have at most %d decimal places.', $currency->value, $currency->minorUnit()));
                    }
                }
            },
        ];
    }

    public function toCommand(?string $actorId): ManualEntryCommand
    {
        $currency = Currency::from($this->string('currency')->value());

        /** @var non-empty-list<array{account_id: string, side: string, amount: string, memo?: string|null}> $lines */
        $lines = $this->validated('lines');

        return new ManualEntryCommand(
            currency: $currency,
            description: $this->string('description')->trim()->value(),
            reason: $this->string('reason')->trim()->value(),
            lines: array_map(static fn (array $line): array => [
                'account_id' => $line['account_id'],
                'side' => Side::from($line['side']),
                'amount' => Money::fromDecimal($line['amount'], $currency),
                'memo' => isset($line['memo']) && trim($line['memo']) !== '' ? trim($line['memo']) : null,
            ], $lines),
            actorId: $actorId,
        );
    }
}
