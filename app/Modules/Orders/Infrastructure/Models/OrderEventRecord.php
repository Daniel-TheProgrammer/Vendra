<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Models;

use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * One entry of an order's history. Append-only: the database rejects
 * updates and deletes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $type
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property array<string, mixed> $data
 * @property string|null $actor_id
 * @property CarbonImmutable $created_at
 */
final class OrderEventRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'order_events';

    protected $fillable = [
        'order_id',
        'type',
        'from_status',
        'to_status',
        'data',
        'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'data' => 'array',
        ];
    }
}
