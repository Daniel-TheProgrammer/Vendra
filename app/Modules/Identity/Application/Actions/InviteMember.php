<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Services\MemberManagementRules;
use App\Modules\Identity\Domain\AlreadyAMember;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Invitation;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Notifications\MemberInvited;
use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Invites an address to the tenant. Re-inviting an address with an open
 * invitation replaces it, so the newest e-mail is the only one that works.
 */
final readonly class InviteMember
{
    private const TTL_DAYS = 7;

    public function __construct(
        private TransactionRunner $transaction,
        private MemberManagementRules $rules,
        private FindTenant $findTenant,
        private Clock $clock,
        private Config $config,
        private AuditTrail $audit,
    ) {}

    public function __invoke(MembershipRole $actorRole, string $actorId, string $tenantId, string $email, MembershipRole $role): Invitation
    {
        $this->rules->ensureCanAssign($actorRole, null, $role);

        $email = mb_strtolower(trim($email));
        $tenant = ($this->findTenant)($tenantId) ?? throw EntityNotFound::withId('Tenant', $tenantId);
        $token = Str::random(48);
        $now = $this->clock->now();

        $invitation = $this->transaction->run(function () use ($tenantId, $email, $role, $actorId, $token, $now): Invitation {
            $existingUserId = User::query()->where('email', $email)->value('id');

            if ($existingUserId !== null && Membership::query()->where('tenant_id', $tenantId)->where('user_id', $existingUserId)->exists()) {
                throw AlreadyAMember::create();
            }

            Invitation::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            return Invitation::query()->create([
                'tenant_id' => $tenantId,
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $actorId,
                'expires_at' => $now->addDays(self::TTL_DAYS),
            ]);
        });

        Notification::route('mail', $email)->notify(new MemberInvited(
            tenantName: $tenant->name,
            roleLabel: str_replace('_', '-', $role->value),
            token: $token,
            acceptUrl: $this->acceptUrl($tenant, $token),
            expiresAt: $invitation->expires_at,
        ));

        $this->audit->record('member.invited', $actorId, $tenantId, [
            'invitation_id' => $invitation->id,
            'email' => $email,
            'role' => $role->value,
        ]);

        return $invitation;
    }

    private function acceptUrl(TenantSnapshot $tenant, string $token): string
    {
        $base = $this->config->get('app.frontend_url', 'http://localhost:3000');

        return sprintf(
            '%s/accept-invitation?%s',
            rtrim(is_string($base) ? $base : '', '/'),
            http_build_query(['token' => $token, 'tenant' => $tenant->slug]),
        );
    }
}
