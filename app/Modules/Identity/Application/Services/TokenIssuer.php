<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Results\IssuedToken;
use App\Modules\Identity\Infrastructure\Models\PersonalAccessToken;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Application\Clock\Clock;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Issues tenant-bound personal access tokens. Sanctum's createToken() cannot
 * carry the tenant, so the token row is written here with the same format
 * Sanctum expects when it authenticates: "<id>|<prefix><random>".
 */
final readonly class TokenIssuer
{
    public function __construct(
        private Clock $clock,
        private Config $config,
    ) {}

    public function issue(User $user, string $tenantId, string $name, ?int $ttlDays = null): IssuedToken
    {
        $ttlDays ??= $this->defaultTtlDays();

        if ($ttlDays < 1 || $ttlDays > $this->maxTtlDays()) {
            throw new InvalidArgumentException(sprintf('Token lifetime must be between 1 and %d days.', $this->maxTtlDays()));
        }

        $plainText = $user->generateTokenString();
        $expiresAt = $this->clock->now()->addDays($ttlDays);

        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
            'tenant_id' => $tenantId,
        ]);

        return new IssuedToken(
            id: $token->id,
            plainTextToken: $token->id.'|'.$plainText,
            tenantId: $tenantId,
            expiresAt: $expiresAt,
        );
    }

    public function maxTtlDays(): int
    {
        $value = $this->config->get('identity.token_max_ttl_days', 365);

        return is_numeric($value) ? (int) $value : 365;
    }

    private function defaultTtlDays(): int
    {
        $value = $this->config->get('identity.token_ttl_days', 30);

        return is_numeric($value) ? (int) $value : 30;
    }
}
