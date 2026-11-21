<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\Login;
use App\Modules\Identity\Application\Actions\Logout;
use App\Modules\Identity\Application\Actions\RegisterUser;
use App\Modules\Identity\Application\Actions\SwitchTenant;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Requests\SwitchTenantRequest;
use App\Modules\Identity\Http\Resources\AuthSessionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AuthController
{
    use ResolvesPrincipal;

    public function register(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        return (new AuthSessionResource($register($request->toCommand())))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request, Login $login): AuthSessionResource
    {
        return new AuthSessionResource($login($request->toCommand()));
    }

    public function switchTenant(SwitchTenantRequest $request, SwitchTenant $switchTenant): AuthSessionResource
    {
        return new AuthSessionResource($switchTenant(
            $this->principal($request),
            $this->accessToken($request),
            $request->tenant(),
        ));
    }

    public function logout(Request $request, Logout $logout): Response
    {
        $logout($this->principal($request), $this->accessToken($request));

        return response()->noContent();
    }
}
