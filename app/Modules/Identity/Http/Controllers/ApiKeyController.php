<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\CreateApiKey;
use App\Modules\Identity\Application\Actions\RevokeApiKey;
use App\Modules\Identity\Application\Actions\RotateApiKey;
use App\Modules\Identity\Application\Commands\CreateApiKeyCommand;
use App\Modules\Identity\Application\Results\IssuedApiKey;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Requests\CreateApiKeyRequest;
use App\Modules\Identity\Http\Resources\ApiKeyResource;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ApiKeyController
{
    use ResolvesPrincipal;

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $keys = ApiKey::query()
            ->where('tenant_id', $context->id())
            ->orderByDesc('created_at')
            ->get()
            ->map(static fn (ApiKey $key): array => (new ApiKeyResource($key))->toArray($request))
            ->values()
            ->all();

        return new JsonResponse(['data' => $keys]);
    }

    public function store(CreateApiKeyRequest $request, TenantContext $context, CreateApiKey $create): JsonResponse
    {
        $issued = $create(new CreateApiKeyCommand(
            tenantId: $context->id(),
            actorId: $this->principal($request)->id,
            name: $request->keyName(),
            scopes: $request->scopes(),
            ttlDays: $request->ttlDays(),
        ));

        return $this->issued($request, $issued);
    }

    public function rotate(Request $request, string $apiKey, TenantContext $context, RotateApiKey $rotate): JsonResponse
    {
        return $this->issued($request, $rotate($this->principal($request)->id, $context->id(), $apiKey));
    }

    public function destroy(Request $request, string $apiKey, TenantContext $context, RevokeApiKey $revoke): Response
    {
        $revoke($this->principal($request)->id, $context->id(), $apiKey);

        return response()->noContent();
    }

    /**
     * The only response that ever contains the secret.
     */
    private function issued(Request $request, IssuedApiKey $issued): JsonResponse
    {
        return new JsonResponse(['data' => [
            ...(new ApiKeyResource($issued->key))->toArray($request),
            'key' => $issued->plainTextKey,
        ]], 201);
    }
}
