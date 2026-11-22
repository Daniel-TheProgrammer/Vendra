<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Application\Services\ApiKeyIssuer;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use App\Shared\Application\Clock\Clock;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Backs the "api-key" guard: turns a bearer value into a usable key or null.
 */
final readonly class AuthenticateApiKey
{
    public function __construct(
        private ApiKeyIssuer $issuer,
        private Clock $clock,
        private Config $config,
    ) {}

    public function __invoke(?string $bearer): ?ApiKey
    {
        if (! ApiKeyIssuer::looksLikeApiKey($bearer)) {
            return null;
        }

        $key = $this->issuer->find((string) $bearer);
        $now = $this->clock->now();

        if (! $key instanceof ApiKey || ! $key->isUsableAt($now)) {
            return null;
        }

        $interval = $this->config->get('identity.api_keys.touch_interval_seconds', 60);

        if ($key->last_used_at === null || $key->last_used_at->addSeconds(is_numeric($interval) ? (int) $interval : 60)->isBefore($now)) {
            // Bypass model events and updated_at: usage is not a change to the key.
            ApiKey::query()->whereKey($key->id)->toBase()->update(['last_used_at' => $now]);
        }

        return $key;
    }
}
