<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Commands\LoginCommand;
use App\Modules\Identity\Application\Results\AuthSession;
use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\InvalidCredentials;
use App\Modules\Identity\Domain\TenantAccessDenied;
use App\Modules\Identity\Domain\TenantSelectionRequired;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\FindTenants;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Modules\Tenancy\Domain\TenantSuspended;
use App\Shared\Application\Audit\AuditTrail;
use Illuminate\Contracts\Hashing\Hasher;

final class Login
{
    /**
     * Hash compared against when the e-mail is unknown, so a miss costs the
     * same time as a wrong password and response timing does not reveal
     * which addresses have accounts.
     */
    private ?string $timingHash = null;

    public function __construct(
        private readonly Hasher $hasher,
        private readonly FindTenant $findTenant,
        private readonly FindTenants $findTenants,
        private readonly TokenIssuer $tokens,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(LoginCommand $command): AuthSession
    {
        $user = User::query()->where('email', mb_strtolower(trim($command->email)))->first();

        if (! $user instanceof User) {
            $this->hasher->check($command->password, $this->timingHash ??= $this->hasher->make('timing-equaliser'));
            $this->audit->record('auth.login_failed', metadata: ['reason' => 'unknown_email']);

            throw InvalidCredentials::create();
        }

        if (! $this->hasher->check($command->password, $user->password)) {
            $this->audit->record('auth.login_failed', $user->id, metadata: ['reason' => 'wrong_password']);

            throw InvalidCredentials::create();
        }

        if ($this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $command->password])->save();
        }

        [$membership, $tenant] = $this->chooseTenant($user, $command->tenant);

        $token = $this->tokens->issue($user, $tenant->id, $command->deviceName);

        $this->audit->record('auth.login', $user->id, $tenant->id, ['token_id' => $token->id]);

        return new AuthSession($token, $user, $tenant, $membership->role);
    }

    /**
     * @return array{Membership, TenantSnapshot}
     */
    private function chooseTenant(User $user, ?string $requested): array
    {
        $memberships = Membership::query()->where('user_id', $user->id)->get()->keyBy('tenant_id');

        if ($requested !== null) {
            $tenant = ($this->findTenant)($requested);
            $membership = $tenant instanceof TenantSnapshot ? $memberships->get($tenant->id) : null;

            if (! $tenant instanceof TenantSnapshot || ! $membership instanceof Membership) {
                throw TenantAccessDenied::create();
            }

            return [$membership, $this->ensureActive($tenant)];
        }

        if ($memberships->isEmpty()) {
            throw TenantAccessDenied::create();
        }

        $tenants = ($this->findTenants)($memberships->keys()->map(strval(...))->values()->all());

        if ($memberships->count() > 1) {
            throw TenantSelectionRequired::among(array_values(array_map(
                static fn (TenantSnapshot $tenant): array => $tenant->summary(),
                $tenants,
            )));
        }

        $membership = $memberships->first();
        $tenant = $tenants[$membership->tenant_id] ?? throw TenantAccessDenied::create();

        return [$membership, $this->ensureActive($tenant)];
    }

    private function ensureActive(TenantSnapshot $tenant): TenantSnapshot
    {
        if (! $tenant->isActive()) {
            throw TenantSuspended::withSlug($tenant->slug);
        }

        return $tenant;
    }
}
