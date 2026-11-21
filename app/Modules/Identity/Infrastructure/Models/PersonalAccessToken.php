<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A bearer token bound to exactly one tenant. The tenant a request runs as is
 * taken from the token, never from anything the client sends.
 *
 * @property string $id
 * @property string $tokenable_id
 * @property string $tenant_id
 * @property string $name
 * @property string $token SHA-256 of the secret part; the secret itself is never stored
 * @property list<string> $abilities
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
final class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'tenant_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
