<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Results\IssuedApiKey;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates keys and recognises them again.
 *
 * Format: vendra_sk_<8 lower-case alphanumerics>_<40 alphanumerics>
 * The first part (prefix) is stored in clear for lookup and display; only a
 * SHA-256 of the whole key is stored, and it is compared in constant time.
 */
final class ApiKeyIssuer
{
    public const PREFIX = 'vendra_sk_';

    private const PATTERN = '/^(vendra_sk_[a-z0-9]{8})_[A-Za-z0-9]{40}$/';

    /**
     * @param  list<ApiScope>  $scopes
     */
    public function issue(
        string $tenantId,
        string $name,
        array $scopes,
        ?string $createdBy,
        ?CarbonImmutable $expiresAt,
        ?string $rotatedFromId = null,
    ): IssuedApiKey {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $prefix = self::PREFIX.Str::lower(Str::random(8));
            $plainText = $prefix.'_'.Str::random(40);

            try {
                $key = ApiKey::query()->create([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'prefix' => $prefix,
                    'secret_hash' => hash('sha256', $plainText),
                    'scopes' => array_values(array_unique(array_map(static fn (ApiScope $s): string => $s->value, $scopes))),
                    'created_by' => $createdBy,
                    'rotated_from_id' => $rotatedFromId,
                    'expires_at' => $expiresAt,
                ]);

                return new IssuedApiKey($key, $plainText);
            } catch (UniqueConstraintViolationException) {
                // Prefix collision (1 in 2.8e12 per pair): draw again.
            }
        }

        throw new RuntimeException('Could not allocate a unique API key prefix.');
    }

    /**
     * Returns the stored key a presented secret belongs to, or null. Says
     * nothing about whether the key is still usable.
     */
    public function find(string $presented): ?ApiKey
    {
        if (preg_match(self::PATTERN, $presented, $matches) !== 1) {
            return null;
        }

        $key = ApiKey::query()->where('prefix', $matches[1])->first();

        if (! $key instanceof ApiKey || ! hash_equals($key->secret_hash, hash('sha256', $presented))) {
            return null;
        }

        return $key;
    }

    public static function looksLikeApiKey(?string $presented): bool
    {
        return is_string($presented) && str_starts_with($presented, self::PREFIX);
    }
}
