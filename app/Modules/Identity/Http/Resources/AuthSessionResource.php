<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Application\Results\AuthSession;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class AuthSessionResource extends ApiResource
{
    public function __construct(AuthSession $session)
    {
        parent::__construct($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuthSession $session */
        $session = $this->resource;

        return [
            'token' => $session->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $session->token->expiresAt->toIso8601String(),
            'user' => (new UserResource($session->user))->toArray($request),
            'tenant' => $session->tenant->summary(),
            'role' => $session->role->value,
        ];
    }
}
