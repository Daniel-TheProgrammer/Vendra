<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\Actions\AcceptInvitation;
use App\Modules\Identity\Application\Actions\InviteMember;
use App\Modules\Identity\Application\Actions\RevokeInvitation;
use App\Modules\Identity\Application\Services\Authorizer;
use App\Modules\Identity\Http\Concerns\ResolvesActorRole;
use App\Modules\Identity\Http\Concerns\ResolvesPrincipal;
use App\Modules\Identity\Http\Requests\AcceptInvitationRequest;
use App\Modules\Identity\Http\Requests\InviteMemberRequest;
use App\Modules\Identity\Http\Resources\AuthSessionResource;
use App\Modules\Identity\Infrastructure\Models\Invitation;
use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class InvitationController
{
    use ResolvesActorRole;
    use ResolvesPrincipal;

    public function index(TenantContext $context, Clock $clock): JsonResponse
    {
        $open = Invitation::query()
            ->where('tenant_id', $context->id())
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $clock->now())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): array => $this->present($invitation))
            ->values()
            ->all();

        return new JsonResponse(['data' => $open]);
    }

    public function store(InviteMemberRequest $request, TenantContext $context, Authorizer $authorizer, InviteMember $invite): JsonResponse
    {
        $tenantId = $context->id();

        $invitation = $invite(
            $this->actorRole($request, $authorizer, $tenantId),
            $this->principal($request)->id,
            $tenantId,
            $request->email(),
            $request->role(),
        );

        return new JsonResponse(['data' => $this->present($invitation)], 201);
    }

    public function destroy(Request $request, string $invitation, TenantContext $context, RevokeInvitation $revoke): Response
    {
        $revoke($this->principal($request)->id, $context->id(), $invitation);

        return response()->noContent();
    }

    public function accept(AcceptInvitationRequest $request, AcceptInvitation $accept): AuthSessionResource
    {
        return new AuthSessionResource($accept($request->toCommand()));
    }

    /**
     * @return array<string, string|null>
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'invited_by' => $invitation->invited_by,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
