<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Infrastructure\Models;

use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event, to one subscription, with every attempt's latest outcome.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subscription_id
 * @property string $event_id
 * @property string $event_type
 * @property string $body
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $last_attempt_at
 * @property int|null $response_code
 * @property int|null $response_ms
 * @property string|null $response_excerpt
 * @property string|null $error
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookSubscription|null $subscription
 */
final class WebhookDelivery extends Model
{
    use BelongsToTenant;

    public const TEST_EVENT = 'webhook.test';

    protected $fillable = [
        'subscription_id',
        'event_id',
        'event_type',
        'body',
        'status',
        'next_attempt_at',
    ];

    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'response_code' => 'integer',
            'response_ms' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function isTest(): bool
    {
        return $this->event_type === self::TEST_EVENT;
    }
}
