<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\ActionNotPermitted;
use App\Modules\Identity\Domain\LastOwnerRequired;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;

/**
 * Who may grant which role, on top of the members.manage permission:
 * only owners hand out or take away ownership; admins manage everyone
 * below owner. A tenant always keeps at least one owner.
 */
final class MemberManagementRules
{
    public function ensureCanAssign(MembershipRole $actor, ?MembershipRole $current, MembershipRole $new): void
    {
        if ($actor === MembershipRole::Owner) {
            return;
        }

        if ($current === MembershipRole::Owner || $new === MembershipRole::Owner) {
            throw ActionNotPermitted::forRole($actor, 'grant or change the owner role');
        }
    }

    public function ensureCanRemove(MembershipRole $actor, MembershipRole $target): void
    {
        if ($actor !== MembershipRole::Owner && $target === MembershipRole::Owner) {
            throw ActionNotPermitted::forRole($actor, 'remove an owner');
        }
    }

    /**
     * Call inside the transaction that demotes or removes $membership.
     * Locks the tenant's owner rows so two concurrent demotions cannot both
     * pass this check.
     */
    public function ensureAnotherOwnerRemains(Membership $membership): void
    {
        if ($membership->role !== MembershipRole::Owner) {
            return;
        }

        $owners = Membership::query()
            ->where('tenant_id', $membership->tenant_id)
            ->where('role', MembershipRole::Owner)
            ->lockForUpdate()
            ->pluck('id');

        if ($owners->reject(static fn (mixed $id): bool => $id === $membership->id)->isEmpty()) {
            throw LastOwnerRequired::create();
        }
    }
}
