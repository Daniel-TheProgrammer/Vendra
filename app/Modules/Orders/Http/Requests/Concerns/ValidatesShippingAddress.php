<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Concerns;

trait ValidatesShippingAddress
{
    /**
     * Rules for a shipping address nested under $parent ("shipping_address"),
     * which is then optional as a whole, or forming the whole body when
     * $parent is null.
     *
     * @return array<string, list<string>>
     */
    protected function shippingAddressRules(?string $parent): array
    {
        $prefix = $parent !== null ? $parent.'.' : '';
        $required = $parent !== null ? 'required_with:'.$parent : 'required';

        return [
            ...($parent !== null ? [$parent => ['sometimes', 'array']] : []),
            $prefix.'name' => [$required, 'string', 'max:200'],
            $prefix.'line1' => [$required, 'string', 'max:200'],
            $prefix.'line2' => ['nullable', 'string', 'max:200'],
            $prefix.'city' => [$required, 'string', 'max:100'],
            $prefix.'region' => ['nullable', 'string', 'max:100'],
            $prefix.'postal_code' => [$required, 'string', 'max:20'],
            $prefix.'country' => [$required, 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }
}
