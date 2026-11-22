<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Commands\AcceptInvitationCommand;
use App\Modules\Identity\Application\Results\AuthSession;
use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\InvalidInvitation;
use App\Modules\Identity\Infrastructure\Models\Invitation;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Domain\TenantSuspended;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Validation\ValidationException;

/**
 * Turns an invitation into a membership and signs the invitee in to the
 * tenant. Holding the mailed token proves control of the inbox, so a new
 * account starts with a verified address and an existing account is joined
 * without its password.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private TransactionRunner $transaction,
        private FindTenant $findTenant,
        private TokenIssuer $tokens,
        private Clock $clock,
        private AuditTrail $audit,
    ) {}

    public function __invoke(AcceptInvitationCommand $command): AuthSession
    {
        return $this->transaction->run(function () use ($command): AuthSession {
            $now = $this->clock->now();

            $invitation = Invitation::query()
                ->where('token_hash', hash('sha256', $command->token))
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof Invitation || ! $invitation->isOpenAt($now)) {
                throw InvalidInvitation::create();
            }

            $tenant = ($this->findTenant)($invitation->tenant_id) ?? throw InvalidInvitation::create();

            if (! $tenant->isActive()) {
                throw TenantSuspended::withSlug($tenant->slug);
            }

            $user = User::query()->where('email', $invitation->email)->first() ?? $this->createUser($command, $invitation);

            Membership::query()->firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->forceFill(['accepted_at' => $now])->save();

            $token = $this->tokens->issue($user, $tenant->id, $command->deviceName);

            $this->audit->record('member.joined', $user->id, $tenant->id, [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role->value,
            ]);

            return new AuthSession($token, $user, $tenant, $invitation->role);
        });
    }

    private function createUser(AcceptInvitationCommand $command, Invitation $invitation): User
    {
        if ($command->name === null || $command->password === null) {
            throw ValidationException::withMessages([
                'name' => 'A name is required to create your account.',
                'password' => 'A password is required to create your account.',
            ]);
        }

        $user = User::query()->create([
            'name' => $command->name,
            'email' => $invitation->email,
            'password' => $command->password,
        ]);

        $user->markEmailAsVerified();

        return $user;
    }
}
