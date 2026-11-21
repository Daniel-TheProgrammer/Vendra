<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Commands\RegisterUserCommand;
use App\Modules\Identity\Application\Results\AuthSession;
use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\EmailAlreadyRegistered;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Application\Actions\CreateTenant;
use App\Modules\Tenancy\Application\Commands\CreateTenantCommand;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transaction\TransactionRunner;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Sign-up: creates the user, their first tenant and an owner membership, and
 * signs them in to that tenant. All or nothing.
 */
final readonly class RegisterUser
{
    public function __construct(
        private TransactionRunner $transaction,
        private CreateTenant $createTenant,
        private TokenIssuer $tokens,
        private AuditTrail $audit,
    ) {}

    public function __invoke(RegisterUserCommand $command): AuthSession
    {
        $session = $this->transaction->run(function () use ($command): AuthSession {
            if (User::query()->where('email', mb_strtolower(trim($command->email)))->exists()) {
                throw EmailAlreadyRegistered::create();
            }

            try {
                $user = User::query()->create([
                    'name' => $command->name,
                    'email' => $command->email,
                    'password' => $command->password,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw EmailAlreadyRegistered::create();
            }

            $tenant = ($this->createTenant)(new CreateTenantCommand(
                name: $command->tenantName,
                slug: $command->tenantSlug,
            ));

            Membership::query()->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => MembershipRole::Owner,
            ]);

            $token = $this->tokens->issue($user, $tenant->id, $command->deviceName);

            $this->audit->record('auth.registered', $user->id, $tenant->id, ['token_id' => $token->id]);

            return new AuthSession($token, $user, TenantSnapshot::fromModel($tenant), MembershipRole::Owner);
        });

        $session->user->sendEmailVerificationNotification();

        return $session;
    }
}
