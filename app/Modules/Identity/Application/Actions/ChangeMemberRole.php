<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Services\MemberManagementRules;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class ChangeMemberRole
{
    public function __construct(
        private TransactionRunner $transaction,
        private MemberManagementRules $rules,
        private AuditTrail $audit,
    ) {}

    public function __invoke(MembershipRole $actorRole, string $actorId, string $tenantId, string $membershipId, MembershipRole $role): Membership
    {
        return $this->transaction->run(function () use ($actorRole, $actorId, $tenantId, $membershipId, $role): Membership {
            $membership = Membership::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->first() ?? throw EntityNotFound::withId('Member', $membershipId);

            $previous = $membership->role;

            if ($previous === $role) {
                return $membership;
            }

            $this->rules->ensureCanAssign($actorRole, $previous, $role);
            $this->rules->ensureAnotherOwnerRemains($membership);

            $membership->forceFill(['role' => $role])->save();

            $this->audit->record('member.role_changed', $actorId, $tenantId, [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'from' => $previous->value,
                'to' => $role->value,
            ]);

            return $membership;
        });
    }
}
