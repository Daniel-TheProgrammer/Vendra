<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\Commands\LoginCommand;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'tenant' => ['nullable', 'string', 'max:63'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function toCommand(): LoginCommand
    {
        return new LoginCommand(
            email: $this->string('email')->value(),
            password: $this->string('password')->value(),
            tenant: $this->filled('tenant') ? $this->string('tenant')->value() : null,
            deviceName: $this->filled('device_name') ? $this->string('device_name')->value() : 'api',
        );
    }
}
