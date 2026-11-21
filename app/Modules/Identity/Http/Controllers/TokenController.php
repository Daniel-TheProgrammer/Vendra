<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\CreatePersonalAccessToken;
use App\Modules\Identity\Application\Actions\RevokePersonalAccessToken;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Requests\CreateTokenRequest;
use App\Modules\Identity\Http\Resources\IssuedTokenResource;
use App\Modules\Identity\Http\Resources\TokenResource;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class TokenController
{
    use ResolvesPrincipal;

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $current = $this->accessToken($request);

        $tokens = $this->principal($request)->tokens()
            ->where('tenant_id', $context->id())
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (PersonalAccessToken $token): array => (new TokenResource($token, $current->id))->toArray($request))
            ->values()
            ->all();

        return new JsonResponse(['data' => $tokens]);
    }

    public function store(CreateTokenRequest $request, TenantContext $context, CreatePersonalAccessToken $create): JsonResponse
    {
        $token = $create($this->principal($request), $context->id(), $request->tokenName(), $request->ttlDays());

        return (new IssuedTokenResource($token))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $token, TenantContext $context, RevokePersonalAccessToken $revoke): Response
    {
        $revoke($this->principal($request), $context->id(), $token);

        return response()->noContent();
    }
}
