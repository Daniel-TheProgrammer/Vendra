<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Application\Results\IssuedApiKey;
use App\Modules\Identity\Application\Services\ApiKeyIssuer;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Authenticates following requests with a real API key.
 */
trait IssuesApiKeys
{
    /**
     * @param  list<ApiScope>  $scopes
     */
    protected function actingAsApiKey(
        ?Tenant $tenant = null,
        array $scopes = [ApiScope::TenantRead],
        ?CarbonImmutable $expiresAt = null,
    ): IssuedApiKey {
        $tenant ??= Tenant::factory()->create();

        $issued = $this->app->make(ApiKeyIssuer::class)->issue($tenant->id, 'test key', $scopes, null, $expiresAt);

        $this->withToken($issued->plainTextKey);
        $this->forgetAuthenticatedUser();

        return $issued;
    }
}
