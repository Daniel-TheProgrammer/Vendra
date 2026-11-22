<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\ChangeMemberRole;
use App\Modules\Identity\Application\Actions\RemoveMember;
use App\Modules\Identity\Application\Queries\ListMembers;
use App\Modules\Identity\Application\Services\Authorizer;
use App\Modules\Identity\Http\Concerns\ResolvesActorRole;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Requests\ChangeMemberRoleRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MemberController
{
    use ResolvesActorRole;
    use ResolvesPrincipal;

    public function index(TenantContext $context, ListMembers $members): JsonResponse
    {
        return new JsonResponse(['data' => $members($context->id())]);
    }

    public function updateRole(
        ChangeMemberRoleRequest $request,
        string $member,
        TenantContext $context,
        Authorizer $authorizer,
        ChangeMemberRole $change,
    ): JsonResponse {
        $tenantId = $context->id();

        $membership = $change(
            $this->actorRole($request, $authorizer, $tenantId),
            $this->principal($request)->id,
            $tenantId,
            $member,
            $request->role(),
        );

        return new JsonResponse(['data' => ['id' => $membership->id, 'role' => $membership->role->value]]);
    }

    public function destroy(Request $request, string $member, TenantContext $context, Authorizer $authorizer, RemoveMember $remove): Response
    {
        $tenantId = $context->id();

        $remove($this->actorRole($request, $authorizer, $tenantId), $this->principal($request)->id, $tenantId, $member);

        return response()->noContent();
    }
}
