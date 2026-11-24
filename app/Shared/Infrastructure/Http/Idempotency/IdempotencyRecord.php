<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Idempotency;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body
 * @property CarbonImmutable $locked_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
final class IdempotencyRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'request_hash',
        'locked_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'response_status' => 'integer',
            'response_headers' => 'array',
            'locked_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
