<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * The outcome of one product group of an import. Written once, never updated.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $import_id
 * @property string $handle
 * @property int $first_row
 * @property int $row_count
 * @property string $outcome created|failed
 * @property string|null $product_id
 * @property list<array{row: int, message: string}>|null $errors
 * @property CarbonImmutable $created_at
 */
final class ProductImportGroup extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CREATED = 'created';

    public const FAILED = 'failed';

    protected $fillable = [
        'import_id',
        'handle',
        'first_row',
        'row_count',
        'outcome',
        'product_id',
        'errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'first_row' => 'integer',
            'row_count' => 'integer',
            'errors' => 'array',
        ];
    }
}
