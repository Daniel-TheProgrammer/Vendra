<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

enum PauseReason: string
{
    case Manual = 'manual';

    /** Too many consecutive failed deliveries (webhooks.pause_after_failures). */
    case Failing = 'failing';
}
