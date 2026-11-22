<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\Invitation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class RevokeInvitation
{
    public function __construct(
        private Clock $clock,
        private AuditTrail $audit,
    ) {}

    public function __invoke(string $actorId, string $tenantId, string $invitationId): void
    {
        $now = $this->clock->now();
        $invitation = Invitation::query()->where('tenant_id', $tenantId)->whereKey($invitationId)->first();

        if (! $invitation instanceof Invitation || ! $invitation->isOpenAt($now)) {
            throw EntityNotFound::withId('Invitation', $invitationId);
        }

        $invitation->forceFill(['revoked_at' => $now])->save();

        $this->audit->record('member.invitation_revoked', $actorId, $tenantId, ['invitation_id' => $invitation->id]);
    }
}
