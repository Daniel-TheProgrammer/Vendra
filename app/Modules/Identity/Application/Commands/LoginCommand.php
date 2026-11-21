<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use SensitiveParameter;

final readonly class LoginCommand
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $password,
        public ?string $tenant,
        public string $deviceName,
    ) {}
}
