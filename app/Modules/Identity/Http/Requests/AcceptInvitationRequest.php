<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\Commands\AcceptInvitationCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AcceptInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Password|null>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function toCommand(): AcceptInvitationCommand
    {
        return new AcceptInvitationCommand(
            token: $this->string('token')->value(),
            name: $this->filled('name') ? $this->string('name')->trim()->value() : null,
            password: $this->filled('password') ? $this->string('password')->value() : null,
            deviceName: $this->filled('device_name') ? $this->string('device_name')->value() : 'api',
        );
    }
}
