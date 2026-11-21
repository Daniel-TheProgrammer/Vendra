<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\UserFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who can sign in. Platform-level: one user may belong to many
 * tenants through memberships.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property CarbonImmutable|null $email_verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasApiTokens<PersonalAccessToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * E-mail addresses are compared case-insensitively, so they are stored lower-case.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
