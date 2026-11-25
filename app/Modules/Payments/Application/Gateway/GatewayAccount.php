<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

/**
 * The tenant's account at the provider, on whose behalf a call is made
 * (with Stripe Connect, the Stripe-Account header).
 */
final readonly class GatewayAccount
{
    public function __construct(
        public string $accountId,
    ) {}
}
