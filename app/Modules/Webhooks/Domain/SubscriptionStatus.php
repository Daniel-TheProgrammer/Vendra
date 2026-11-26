<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

enum SubscriptionStatus: string
{
    /** Receives deliveries. */
    case Active = 'active';

    /** Receives nothing until resumed: paused by the tenant, or by Vendra after repeated failures. */
    case Paused = 'paused';
}
