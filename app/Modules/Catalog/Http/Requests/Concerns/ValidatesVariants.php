<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Modules\Catalog\Application\Commands\VariantData;
use App\Modules\Catalog\Domain\Sku;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Validation and mapping for the variant payload, shared by product creation
 * and "add a variant".
 *
 *   { "sku": "TEE-RED-M", "name": "Red / M", "options": {"colour": "red", "size": "M"},
 *     "prices": [{"currency": "USD", "amount": "19.99"}] }
 *
 * Amounts are decimal strings in major units, never JSON numbers, so no client
 * float rounding can creep in.
 */
trait ValidatesVariants
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function variantRules(string $prefix): array
    {
        return [
            $prefix.'sku' => ['required', 'string', 'regex:'.Sku::PATTERN],
            $prefix.'name' => ['nullable', 'string', 'max:200'],
            $prefix.'options' => ['sometimes', 'array', 'max:10'],
            $prefix.'options.*' => ['required', 'string', 'max:100'],
            $prefix.'prices' => ['sometimes', 'array', 'max:20'],
            $prefix.'prices.*.currency' => ['required', 'string', Rule::enum(Currency::class)],
            $prefix.'prices.*.amount' => self::amountRules(),
        ];
    }

    /**
     * @return list<string>
     */
    protected static function amountRules(): array
    {
        return ['required', 'string', 'regex:/^\d{1,13}(\.\d{1,3})?$/'];
    }

    /**
     * Checks what field rules cannot express: options must be a name => value
     * object, one price per currency, and amounts must fit the currency's
     * minor unit (no "1.5" yen).
     */
    protected function validateVariantPayload(Validator $validator, string $prefix, mixed $variant): void
    {
        if (! is_array($variant)) {
            return;
        }

        $options = $variant['options'] ?? [];

        if (is_array($options) && $options !== [] && array_is_list($options)) {
            $validator->errors()->add($prefix.'options', 'The options must be an object of name/value pairs.');
        }

        $prices = $variant['prices'] ?? [];
        $seen = [];

        foreach (is_array($prices) ? $prices : [] as $index => $price) {
            $currency = is_array($price) && is_string($price['currency'] ?? null) ? Currency::tryFrom($price['currency']) : null;
            $amount = is_array($price) ? ($price['amount'] ?? null) : null;

            if (! $currency instanceof Currency || ! is_string($amount)) {
                continue;
            }

            if (isset($seen[$currency->value])) {
                $validator->errors()->add($prefix.'prices.'.$index.'.currency', sprintf('There is already a %s price for this variant.', $currency->value));
            }

            $seen[$currency->value] = true;

            if (! self::moneyOrNull($amount, $currency) instanceof Money) {
                $validator->errors()->add(
                    $prefix.'prices.'.$index.'.amount',
                    sprintf('%s amounts have at most %d decimal places.', $currency->value, $currency->minorUnit()),
                );
            }
        }
    }

    /**
     * Maps an already validated payload.
     *
     * @param  array<string, mixed>  $variant
     */
    protected function variantFrom(array $variant): VariantData
    {
        $options = [];

        foreach ((array) ($variant['options'] ?? []) as $name => $value) {
            $options[(string) $name] = (string) $value;
        }

        $prices = [];

        foreach ((array) ($variant['prices'] ?? []) as $price) {
            /** @var array{currency: string, amount: string} $price */
            $prices[] = Money::fromDecimal($price['amount'], Currency::from($price['currency']));
        }

        $name = $variant['name'] ?? null;

        return new VariantData(
            sku: (string) $variant['sku'],
            name: is_string($name) && trim($name) !== '' ? trim($name) : null,
            options: $options,
            prices: $prices,
        );
    }

    protected static function moneyOrNull(string $amount, Currency $currency): ?Money
    {
        try {
            return Money::fromDecimal($amount, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
