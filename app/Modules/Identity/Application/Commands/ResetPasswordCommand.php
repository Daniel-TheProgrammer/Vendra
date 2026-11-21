<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use SensitiveParameter;

final readonly class ResetPasswordCommand
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $token,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
