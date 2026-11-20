<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base class for every persisted model.
 *
 * - UUID v7 primary keys (time-ordered, index friendly).
 * - Timestamps are immutable Carbon instances.
 *
 * Subclasses that override casts() must merge the parent's:
 *   return [...parent::casts(), 'status' => Status::class];
 */
abstract class Model extends EloquentModel
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
