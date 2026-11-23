<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Modules\Catalog\Domain\ImportStatus;
use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A CSV upload and its progress. The counters are advanced in the same
 * transaction as the work they count, so they are exact even after a crash.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ImportStatus $status
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $failed_rows
 * @property int $created_products
 * @property string|null $failure
 * @property string|null $actor_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProductImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'status',
        'filename',
        'disk',
        'path',
        'total_rows',
        'actor_id',
    ];

    protected $attributes = [
        'processed_rows' => 0,
        'failed_rows' => 0,
        'created_products' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'failed_rows' => 'integer',
            'created_products' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<ProductImportGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(ProductImportGroup::class, 'import_id');
    }
}
