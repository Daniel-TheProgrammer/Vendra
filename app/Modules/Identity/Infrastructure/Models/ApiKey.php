<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\ApiKeyFactory;
use App\Modules\Identity\Domain\ApiScope;
use App\Shared\Infrastructure\Persistence\Model;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Authenticatable as AuthenticatableBehaviour;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine credential bound to one tenant with an explicit list of scopes.
 * It authenticates as itself, never as the user who created it.
 *
 * Platform-level table: looked up by prefix before the tenant is known.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $secret_hash
 * @property list<string> $scopes
 * @property string|null $created_by
 * @property string|null $rotated_from_id
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $expiry_notified_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ApiKey extends Model implements Authenticatable
{
    use AuthenticatableBehaviour;

    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'prefix',
        'secret_hash',
        'scopes',
        'created_by',
        'rotated_from_id',
        'expires_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'scopes' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'expiry_notified_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function isUsableAt(CarbonInterface $moment): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isAfter($moment));
    }

    public function allows(ApiScope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * A key has no password and no "remember me".
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    protected static function newFactory(): ApiKeyFactory
    {
        return ApiKeyFactory::new();
    }
}
