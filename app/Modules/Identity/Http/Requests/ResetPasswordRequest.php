<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Application\Commands\ResetPasswordCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Password|null>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    public function toCommand(): ResetPasswordCommand
    {
        return new ResetPasswordCommand(
            email: $this->string('email')->value(),
            token: $this->string('token')->value(),
            password: $this->string('password')->value(),
        );
    }
}
