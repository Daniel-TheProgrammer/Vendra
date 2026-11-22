<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Concerns;

use App\Modules\Identity\Application\Services\Authorizer;
use App\Modules\Identity\Domain\MembershipRole;
use Illuminate\Http\Request;

trait ResolvesActorRole
{
    protected function actorRole(Request $request, Authorizer $authorizer, string $tenantId): MembershipRole
    {
        return $authorizer->roleOf($request->user(), $tenantId);
    }
}
