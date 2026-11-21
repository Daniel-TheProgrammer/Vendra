<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\Commands\RegisterUserCommand;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string|Password|null>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'tenant_name' => ['required', 'string', 'max:120'],
            'tenant_slug' => ['nullable', 'string', 'max:63'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function toCommand(): RegisterUserCommand
    {
        return new RegisterUserCommand(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->value(),
            password: $this->string('password')->value(),
            tenantName: $this->string('tenant_name')->trim()->value(),
            tenantSlug: $this->filled('tenant_slug') ? $this->string('tenant_slug')->value() : null,
            deviceName: $this->filled('device_name') ? $this->string('device_name')->value() : 'api',
        );
    }
}
