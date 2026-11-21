<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Queries\ListUserMemberships;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeController
{
    use ResolvesPrincipal;

    public function __invoke(Request $request, TenantContext $context, ListUserMemberships $memberships): JsonResponse
    {
        $user = $this->principal($request);
        $tenantId = $context->id();
        $all = $memberships($user->id);

        $current = null;
        $listed = [];

        foreach ($all as $membership) {
            $entry = ['tenant' => $membership['tenant'], 'role' => $membership['role']->value];
            $listed[] = $entry;

            if ($membership['tenant']['id'] === $tenantId) {
                $current = $entry;
            }
        }

        return new JsonResponse(['data' => [
            'user' => (new UserResource($user))->toArray($request),
            'tenant' => $current['tenant'] ?? null,
            'role' => $current['role'] ?? null,
            'memberships' => $listed,
        ]]);
    }
}
