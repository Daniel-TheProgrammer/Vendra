<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Models;

use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShippingAddress;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * The order aggregate. Status only changes through moveTo(), which applies
 * OrderStatus's transition rules; every change bumps the version.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $number
 * @property string $customer_id
 * @property OrderStatus $status
 * @property Currency $currency
 * @property int $subtotal
 * @property int $tax
 * @property int $total
 * @property array<string, string|null>|null $shipping_address
 * @property int $version
 * @property string|null $placed_by
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer $customer
 * @property-read Collection<int, OrderLine> $lines
 */
final class Order extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'number',
        'customer_id',
        'status',
        'currency',
        'subtotal',
        'tax',
        'total',
        'shipping_address',
        'placed_by',
        'placed_at',
    ];

    protected $attributes = [
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'number' => 'integer',
            'status' => OrderStatus::class,
            'currency' => Currency::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'shipping_address' => 'array',
            'version' => 'integer',
            'placed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position');
    }

    /**
     * @throws IllegalTransition
     */
    public function moveTo(OrderStatus $next, CarbonImmutable $at): OrderStatus
    {
        $previous = $this->status;
        $this->status = $previous->transitionTo($next);

        match ($next) {
            OrderStatus::Cancelled => $this->cancelled_at = $at,
            OrderStatus::Fulfilled => $this->fulfilled_at = $at,
            default => null,
        };

        $this->version++;

        return $previous;
    }

    public function shippingAddress(): ?ShippingAddress
    {
        return $this->shipping_address !== null ? ShippingAddress::fromArray($this->shipping_address) : null;
    }

    public function money(int $minor): Money
    {
        return Money::of($minor, $this->currency);
    }

    /**
     * The order's lines as stock quantities, for reserving, releasing and
     * shipping.
     *
     * @return non-empty-list<StockLine>
     */
    public function stockLines(): array
    {
        $lines = array_values(array_map(
            static fn (OrderLine $line): StockLine => new StockLine($line->variant_id, $line->quantity),
            $this->lines->all(),
        ));

        return $lines !== [] ? $lines : throw new LogicException(sprintf('Order %s has no lines; every order is placed with at least one.', $this->id));
    }

    /**
     * The reference stock movements carry for this order.
     */
    public static function stockReference(string $orderId): string
    {
        return 'order:'.$orderId;
    }
}
