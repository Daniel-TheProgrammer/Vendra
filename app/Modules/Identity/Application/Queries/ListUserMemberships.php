<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Identity\Infrastructure\Models\Membership;
use App\Modules\Tenancy\Application\Queries\FindTenants;

final readonly class ListUserMemberships
{
    public function __construct(
        private FindTenants $findTenants,
    ) {}

    /**
     * @return list<array{tenant: array{id: string, slug: string, name: string}, role: MembershipRole}>
     */
    public function __invoke(string $userId): array
    {
        $memberships = Membership::query()->where('user_id', $userId)->orderBy('created_at')->get();
        $tenants = ($this->findTenants)($memberships->pluck('tenant_id')->map(strval(...))->values()->all());

        $result = [];

        foreach ($memberships as $membership) {
            $tenant = $tenants[$membership->tenant_id] ?? null;

            if ($tenant !== null) {
                $result[] = ['tenant' => $tenant->summary(), 'role' => $membership->role];
            }
        }

        return $result;
    }
}
