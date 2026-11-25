<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * An inbound gateway event, stored verbatim before it is processed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string $gateway_event_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $gateway_created_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property string|null $outcome applied, ignored or unmatched
 * @property int $attempts
 * @property string|null $last_error
 */
final class PaymentEventRecord extends Model
{
    use BelongsToTenant;

    public const OUTCOME_APPLIED = 'applied';

    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_UNMATCHED = 'unmatched';

    public $timestamps = false;

    protected $table = 'payment_events';

    protected $fillable = [
        'gateway',
        'gateway_event_id',
        'type',
        'payload',
        'gateway_created_at',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'gateway_created_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * The object the event is about (data.object).
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $data = $this->payload['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        if (! is_array($object)) {
            return [];
        }

        /** @var array<string, mixed> $object */
        return $object;
    }
}
