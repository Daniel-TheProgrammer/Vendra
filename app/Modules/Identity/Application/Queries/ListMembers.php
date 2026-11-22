<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;

final class ListMembers
{
    /**
     * @return list<array{id: string, user: array{id: string, name: string, email: string}, role: string, joined_at: string|null}>
     */
    public function __invoke(string $tenantId): array
    {
        $memberships = Membership::query()
            ->with('user')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at')
            ->get();

        $members = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            // The foreign key cascades, so a membership without a user only
            // exists mid-deletion; skip rather than fail the listing.
            if (! $user instanceof User) {
                continue;
            }

            $members[] = [
                'id' => $membership->id,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'role' => $membership->role->value,
                'joined_at' => $membership->created_at?->toIso8601String(),
            ];
        }

        return $members;
    }
}
