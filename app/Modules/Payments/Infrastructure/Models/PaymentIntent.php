<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Payments\Domain\IllegalPaymentTransition;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Domain\Settlement;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * One attempt to collect an order's total. Status only changes through
 * moveTo(), which applies PaymentIntentStatus's rules and bumps the version.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $gateway
 * @property string|null $gateway_ref
 * @property int $amount_minor
 * @property Currency $currency
 * @property PaymentIntentStatus $status
 * @property int $version
 * @property int|null $fee_minor
 * @property Settlement|null $settlement
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $gateway_updated_at
 * @property CarbonImmutable|null $captured_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PaymentIntent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'order_id',
        'gateway',
        'amount_minor',
        'currency',
        'status',
        'created_by',
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
            'amount_minor' => 'integer',
            'currency' => Currency::class,
            'status' => PaymentIntentStatus::class,
            'version' => 'integer',
            'fee_minor' => 'integer',
            'settlement' => Settlement::class,
            'gateway_updated_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @throws IllegalPaymentTransition
     */
    public function moveTo(PaymentIntentStatus $next, CarbonImmutable $at): void
    {
        $this->status = $this->status->transitionTo($next);

        match ($next) {
            PaymentIntentStatus::Succeeded => $this->captured_at = $at,
            PaymentIntentStatus::Failed => $this->failed_at = $at,
            PaymentIntentStatus::Cancelled => $this->cancelled_at = $at,
            default => null,
        };

        $this->version++;
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }

    public function fee(): ?Money
    {
        return $this->fee_minor !== null ? Money::of($this->fee_minor, $this->currency) : null;
    }
}
