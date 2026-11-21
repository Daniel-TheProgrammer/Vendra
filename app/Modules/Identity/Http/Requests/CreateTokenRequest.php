<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\Services\TokenIssuer;
use Illuminate\Foundation\Http\FormRequest;

final class CreateTokenRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $max = $this->container->make(TokenIssuer::class)->maxTtlDays();

        return [
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:'.$max],
        ];
    }

    public function tokenName(): string
    {
        return $this->string('name')->trim()->value();
    }

    public function ttlDays(): ?int
    {
        return $this->filled('expires_in_days') ? $this->integer('expires_in_days') : null;
    }
}
