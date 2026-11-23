<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\PriceWindow;
use App\Modules\Catalog\Http\Requests\Concerns\ValidatesVariants;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Http\Rfc3339;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /variants/{variant}/scheduled-prices
 *   { "currency": "USD", "amount": "14.99",
 *     "valid_from": "2026-11-27T00:00:00-05:00", "valid_to": "2026-12-01T00:00:00-05:00" }
 *
 * The window is half-open: the price applies from valid_from up to, but not
 * including, valid_to. Either bound may be left out, not both.
 */
final class SchedulePriceRequest extends FormRequest
{
    use ValidatesVariants;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'amount' => self::amountRules(),
            'valid_from' => ['nullable', 'required_without:valid_to', ...Rfc3339::rules()],
            'valid_to' => ['nullable', 'required_without:valid_from', ...Rfc3339::rules()],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! self::moneyOrNull($this->string('amount')->value(), $this->currency()) instanceof Money) {
                    $validator->errors()->add('amount', sprintf(
                        '%s amounts have at most %d decimal places.',
                        $this->currency()->value,
                        $this->currency()->minorUnit(),
                    ));
                }

                $from = $this->instant('valid_from');
                $to = $this->instant('valid_to');

                if ($from instanceof CarbonImmutable && $to instanceof CarbonImmutable && $from >= $to) {
                    $validator->errors()->add('valid_to', 'The window must end after it starts.');
                }

                if ($to instanceof CarbonImmutable && $to <= app(Clock::class)->now()) {
                    $validator->errors()->add('valid_to', 'A scheduled price cannot end in the past.');
                }
            },
        ];
    }

    public function currency(): Currency
    {
        return Currency::from($this->string('currency')->value());
    }

    public function price(): Money
    {
        return Money::fromDecimal($this->string('amount')->value(), $this->currency());
    }

    public function window(): PriceWindow
    {
        return new PriceWindow($this->instant('valid_from'), $this->instant('valid_to'));
    }

    private function instant(string $key): ?CarbonImmutable
    {
        return $this->filled($key) ? Rfc3339::parse($this->string($key)->value()) : null;
    }
}
