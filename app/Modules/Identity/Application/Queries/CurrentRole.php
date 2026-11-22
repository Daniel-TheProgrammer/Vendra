<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;

final class CurrentRole
{
    public function __invoke(string $userId, string $tenantId): ?MembershipRole
    {
        $membership = Membership::query()
            ->where('user_id', $userId)
            ->where('tenant_id', $tenantId)
            ->first();

        return $membership?->role;
    }
}
