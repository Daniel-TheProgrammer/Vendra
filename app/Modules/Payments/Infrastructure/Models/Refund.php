<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Modules\Payments\Domain\IllegalRefundTransition;
use App\Modules\Payments\Domain\RefundStatus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_intent_id
 * @property string $gateway
 * @property string|null $gateway_ref
 * @property int $amount_minor
 * @property Currency $currency
 * @property RefundStatus $status
 * @property int $version
 * @property string|null $reason
 * @property string|null $failure_code
 * @property string|null $requested_by
 * @property CarbonImmutable|null $succeeded_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PaymentIntent $paymentIntent
 */
final class Refund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'payment_intent_id',
        'gateway',
        'gateway_ref',
        'amount_minor',
        'currency',
        'status',
        'reason',
        'requested_by',
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
            'status' => RefundStatus::class,
            'version' => 'integer',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentIntent, $this>
     */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /**
     * @throws IllegalRefundTransition
     */
    public function moveTo(RefundStatus $next, CarbonImmutable $at): void
    {
        $this->status = $this->status->transitionTo($next);

        match ($next) {
            RefundStatus::Succeeded => $this->succeeded_at = $at,
            RefundStatus::Failed => $this->failed_at = $at,
            RefundStatus::Pending => null,
        };

        $this->version++;
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }
}
