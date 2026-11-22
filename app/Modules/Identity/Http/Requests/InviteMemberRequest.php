<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\MembershipRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class InviteMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', 'string', new Enum(MembershipRole::class)],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->value();
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from($this->string('role')->value());
    }
}
