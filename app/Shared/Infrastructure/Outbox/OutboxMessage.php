<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;

/**
 * A recorded domain event. Platform table: the relay reads every tenant's
 * messages; tenant-facing reads filter on tenant_id explicitly.
 *
 * @property string $id the event id
 * @property string $tenant_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $dead_at
 * @property int $attempts
 * @property string|null $last_error
 */
final class OutboxMessage extends Model
{
    public $timestamps = false;

    protected $table = 'outbox_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'dead_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
