<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Infrastructure\Models;

use App\Modules\Tenancy\Database\Factories\TenantFactory;
use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Platform-level table: not tenant-scoped and not under row-level security.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantPlan $plan
 * @property TenantStatus $status
 * @property array<string, mixed> $settings
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'plan',
        'status',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'plan' => TenantPlan::class,
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
