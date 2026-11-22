<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\MembershipRole;
use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property MembershipRole $role
 * @property string $token_hash
 * @property string|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Invitation extends Model
{
    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'role' => MembershipRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isOpenAt(CarbonInterface $moment): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isAfter($moment);
    }
}
