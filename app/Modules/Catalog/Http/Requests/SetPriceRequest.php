<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Requests\Concerns\ValidatesVariants;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * PUT /variants/{variant}/prices/{currency}  { "amount": "19.99" }
 * The route only matches supported currency codes.
 */
final class SetPriceRequest extends FormRequest
{
    use ValidatesVariants;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['amount' => self::amountRules()];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $amount = $this->input('amount');

                if (is_string($amount) && ! self::moneyOrNull($amount, $this->currency()) instanceof Money) {
                    $validator->errors()->add('amount', sprintf(
                        '%s amounts have at most %d decimal places.',
                        $this->currency()->value,
                        $this->currency()->minorUnit(),
                    ));
                }
            },
        ];
    }

    public function currency(): Currency
    {
        $code = $this->route('currency');

        return Currency::tryFrom(is_string($code) ? $code : '')
            ?? throw new LogicException('The route must constrain {currency} to supported codes.');
    }

    public function price(): Money
    {
        return Money::fromDecimal($this->string('amount')->value(), $this->currency());
    }
}
