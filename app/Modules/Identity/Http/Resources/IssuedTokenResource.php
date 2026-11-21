<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Results\IssuedToken;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class IssuedTokenResource extends ApiResource
{
    public function __construct(IssuedToken $token)
    {
        parent::__construct($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IssuedToken $token */
        $token = $this->resource;

        return [
            'id' => $token->id,
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->expiresAt->toIso8601String(),
        ];
    }
}
