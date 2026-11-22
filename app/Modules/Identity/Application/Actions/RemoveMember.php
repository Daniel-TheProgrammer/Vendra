<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Services\MemberManagementRules;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Removes a member and deletes their tokens for this tenant. Their account
 * and memberships elsewhere are untouched.
 */
final readonly class RemoveMember
{
    public function __construct(
        private TransactionRunner $transaction,
        private MemberManagementRules $rules,
        private AuditTrail $audit,
    ) {}

    public function __invoke(MembershipRole $actorRole, string $actorId, string $tenantId, string $membershipId): void
    {
        $this->transaction->run(function () use ($actorRole, $actorId, $tenantId, $membershipId): void {
            $membership = Membership::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($membershipId)
                ->lockForUpdate()
                ->first() ?? throw EntityNotFound::withId('Member', $membershipId);

            $this->rules->ensureCanRemove($actorRole, $membership->role);
            $this->rules->ensureAnotherOwnerRemains($membership);

            $membership->delete();

            $revoked = PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->where('tokenable_id', $membership->user_id)
                ->where('tenant_id', $tenantId)
                ->delete();

            $this->audit->record('member.removed', $actorId, $tenantId, [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'role' => $membership->role->value,
                'revoked_tokens' => $revoked,
            ]);
        });
    }
}
