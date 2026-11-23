<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\StockMovementType;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * Append-only history of every stock change; the database rejects updates
 * and deletes. Replaying a variant's movements reproduces its level.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variant_id
 * @property StockMovementType $type
 * @property int $quantity
 * @property string|null $reason
 * @property string|null $reference
 * @property string|null $note
 * @property int $on_hand_after
 * @property int $reserved_after
 * @property string|null $actor_id
 * @property CarbonImmutable $created_at
 */
final class StockMovement extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'variant_id',
        'type',
        'quantity',
        'reason',
        'reference',
        'note',
        'on_hand_after',
        'reserved_after',
        'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'type' => StockMovementType::class,
            'quantity' => 'integer',
            'on_hand_after' => 'integer',
            'reserved_after' => 'integer',
        ];
    }
}
