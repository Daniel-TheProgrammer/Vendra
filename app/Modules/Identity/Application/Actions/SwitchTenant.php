<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\Results\AuthSession;
use App\Modules\Identity\Application\Services\TokenIssuer;
use App\Modules\Identity\Domain\TenantAccessDenied;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Tenancy\Application\Queries\FindTenant;
use App\Modules\Tenancy\Application\Queries\TenantSnapshot;
use App\Modules\Tenancy\Domain\TenantSuspended;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transaction\TransactionRunner;

/**
 * Exchanges the current token for one bound to another tenant the user
 * belongs to. The old token stops working immediately.
 */
final readonly class SwitchTenant
{
    public function __construct(
        private TransactionRunner $transaction,
        private FindTenant $findTenant,
        private TokenIssuer $tokens,
        private AuditTrail $audit,
    ) {}

    public function __invoke(User $user, PersonalAccessToken $currentToken, string $tenant): AuthSession
    {
        $target = ($this->findTenant)($tenant);
        $membership = $target instanceof TenantSnapshot ? Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $target->id)
            ->first() : null;

        if (! $target instanceof TenantSnapshot || ! $membership instanceof Membership) {
            throw TenantAccessDenied::create();
        }

        if (! $target->isActive()) {
            throw TenantSuspended::withSlug($target->slug);
        }

        return $this->transaction->run(function () use ($user, $currentToken, $target, $membership): AuthSession {
            $token = $this->tokens->issue($user, $target->id, $currentToken->name);
            $currentToken->delete();

            $this->audit->record('auth.tenant_switched', $user->id, $target->id, [
                'from_tenant_id' => $currentToken->tenant_id,
                'revoked_token_id' => $currentToken->id,
                'token_id' => $token->id,
            ]);

            return new AuthSession($token, $user, $target, $membership->role);
        });
    }
}
