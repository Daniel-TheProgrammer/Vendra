<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Application\Commands\OrderLineInput;
use App\Modules\Orders\Application\Commands\PlaceOrderCommand;
use App\Modules\Orders\Domain\ShippingAddress;
use App\Modules\Orders\Http\Requests\Concerns\ValidatesShippingAddress;
use App\Shared\Domain\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /orders
 *
 *   { "currency": "USD",
 *     "customer": { "email": "ada@example.com", "name": "Ada Lovelace" },   or "customer_id": "…"
 *     "lines": [ { "variant_id": "…", "quantity": 2 } ],
 *     "shipping_address": { "name": "…", "line1": "…", "city": "…", "postal_code": "…", "country": "GB" } }
 *
 * Prices are never sent: every line is priced by the catalog at placement.
 */
final class PlaceOrderRequest extends FormRequest
{
    use ValidatesShippingAddress;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'customer_id' => ['required_without:customer', 'prohibits:customer', 'uuid'],
            'customer' => ['required_without:customer_id', 'array'],
            'customer.email' => ['required_with:customer', 'string', 'email:rfc', 'max:254'],
            'customer.name' => ['nullable', 'string', 'max:200'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:'.PlaceOrderCommand::MAX_LINES],
            'lines.*' => ['required', 'array'],
            'lines.*.variant_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.OrderLineInput::MAX_QUANTITY],
            ...$this->shippingAddressRules('shipping_address'),
        ];
    }

    public function toCommand(?string $actorId): PlaceOrderCommand
    {
        /** @var non-empty-list<array{variant_id: string, quantity: int|string}> $lines */
        $lines = $this->validated('lines');
        $address = $this->validated('shipping_address');
        $name = $this->validated('customer.name');

        return new PlaceOrderCommand(
            currency: Currency::from($this->string('currency')->value()),
            lines: array_map(static fn (array $line): OrderLineInput => new OrderLineInput($line['variant_id'], (int) $line['quantity']), $lines),
            customerId: $this->filled('customer_id') ? $this->string('customer_id')->value() : null,
            customerEmail: $this->filled('customer') ? $this->string('customer.email')->trim()->value() : null,
            customerName: is_string($name) && trim($name) !== '' ? trim($name) : null,
            shippingAddress: is_array($address) ? ShippingAddress::fromArray($address) : null,
            actorId: $actorId,
        );
    }
}
