<?php

declare(strict_types=1);

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Infrastructure\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only: these keys have no usable secret. Tests that need to
 * authenticate issue a real key through ApiKeyIssuer.
 *
 * @extends Factory<ApiKey>
 */
final class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'prefix' => 'vendra_sk_'.Str::lower(Str::random(8)),
            'secret_hash' => hash('sha256', Str::random(64)),
            'scopes' => [ApiScope::TenantRead->value],
        ];
    }
}
