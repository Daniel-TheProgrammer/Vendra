<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\ApiScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

final class CreateApiKeyRequest extends FormRequest
{
    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        $max = config('identity.api_keys.max_ttl_days', 730);

        return [
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(ApiScope::values())],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.(is_numeric($max) ? (int) $max : 730)],
        ];
    }

    public function keyName(): string
    {
        return $this->string('name')->trim()->value();
    }

    /**
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        $values = $this->array('scopes');

        return array_values(array_map(static fn (mixed $value): ApiScope => ApiScope::from((string) $value), $values));
    }

    public function ttlDays(): ?int
    {
        return $this->filled('expires_in_days') ? $this->integer('expires_in_days') : null;
    }
}
