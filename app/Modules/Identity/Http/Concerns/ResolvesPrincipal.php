<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Concerns;

use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * Typed access to the authenticated user and the token that authenticated
 * the request, for controllers behind auth:sanctum.
 */
trait ResolvesPrincipal
{
    protected function principal(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    protected function accessToken(Request $request): PersonalAccessToken
    {
        $token = $this->principal($request)->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        return $token;
    }
}
