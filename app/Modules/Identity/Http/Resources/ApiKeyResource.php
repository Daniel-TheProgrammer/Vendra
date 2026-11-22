<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * A key as listed back. Never contains the secret; the prefix identifies it.
 */
final class ApiKeyResource extends ApiResource
{
    public function __construct(ApiKey $key)
    {
        parent::__construct($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApiKey $key */
        $key = $this->resource;

        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'status' => match (true) {
                $key->revoked_at !== null => 'revoked',
                $key->expires_at !== null && $key->expires_at->isPast() => 'expired',
                default => 'active',
            },
            'rotated_from_id' => $key->rotated_from_id,
            'created_by' => $key->created_by,
            'last_used_at' => $key->last_used_at?->toIso8601String(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'created_at' => $key->created_at?->toIso8601String(),
        ];
    }
}
