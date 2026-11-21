<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Infrastructure\Http\Resources\ApiResource;
use Illuminate\Http\Request;

final class UserResource extends ApiResource
{
    public function __construct(User $user)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
