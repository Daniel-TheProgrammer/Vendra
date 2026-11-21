<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * A token as listed back to its owner. The secret is never included.
 */
final class TokenResource extends ApiResource
{
    public function __construct(PersonalAccessToken $token, private readonly ?string $currentTokenId = null)
    {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;

        return [
            'id' => $token->id,
            'name' => $token->name,
            'current' => $token->id === $this->currentTokenId,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
