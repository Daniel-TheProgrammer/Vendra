<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Models;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string|null $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Customer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'email',
        'name',
    ];
}
