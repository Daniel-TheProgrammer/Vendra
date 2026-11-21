<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\MembershipFactory;
use App\Modules\Identity\Domain\MembershipRole;
use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a user to a tenant with a role. Platform-level table: it is read
 * before a tenant is chosen (login) and across tenants (listing a user's
 * workspaces), so it is not under row-level security.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property MembershipRole $role
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'role' => MembershipRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): MembershipFactory
    {
        return MembershipFactory::new();
    }
}
