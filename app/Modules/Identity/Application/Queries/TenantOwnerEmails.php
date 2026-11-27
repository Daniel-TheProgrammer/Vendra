<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;

/**
 * Where to send what only a tenant's owners need to know (a webhook paused,
 * say). Verified addresses only.
 */
final readonly class TenantOwnerEmails
{
    /**
     * @return list<string>
     */
    public function __invoke(string $tenantId): array
    {
        $emails = [];

        foreach (Membership::query()->with('user')->where('tenant_id', $tenantId)->where('role', MembershipRole::Owner)->get() as $membership) {
            $user = $membership->user;

            if ($user instanceof User && $user->email_verified_at !== null) {
                $emails[] = $user->email;
            }
        }

        return $emails;
    }
}
