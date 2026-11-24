<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Domain\ShippingAddress;
use App\Modules\Orders\Http\Requests\Concerns\ValidatesShippingAddress;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /orders/{order}/shipping-address with the address as the body.
 */
final class ShippingAddressRequest extends FormRequest
{
    use ValidatesShippingAddress;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->shippingAddressRules(null);
    }

    public function address(): ShippingAddress
    {
        return ShippingAddress::fromArray($this->validated());
    }
}
