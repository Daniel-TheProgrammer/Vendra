<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use SensitiveParameter;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
        public string $tenantName,
        public ?string $tenantSlug,
        public string $deviceName,
    ) {}
}
