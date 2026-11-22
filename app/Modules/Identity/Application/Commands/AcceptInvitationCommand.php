<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use SensitiveParameter;

/**
 * Name and password are required only when no account exists for the
 * invited address yet.
 */
final readonly class AcceptInvitationCommand
{
    public function __construct(
        #[SensitiveParameter]
        public string $token,
        public ?string $name,
        #[SensitiveParameter]
        public ?string $password,
        public string $deviceName,
    ) {}
}
