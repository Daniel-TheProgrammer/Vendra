<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Support;

use App\Shared\Infrastructure\Persistence\Model;
use App\Shared\Tenancy\BelongsToTenant;

/**
 * Minimal tenant-owned model backed by a table the tests create on the fly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $body
 */
final class TenantNote extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_notes';

    protected $fillable = ['body', 'tenant_id'];
}
