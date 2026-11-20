<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

enum TenantPlan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    /**
     * API requests per minute the plan allows. Enforced from Phase 9 onward.
     */
    public function requestsPerMinute(): int
    {
        return match ($this) {
            self::Free => 60,
            self::Pro => 600,
            self::Enterprise => 3000,
        };
    }
}
