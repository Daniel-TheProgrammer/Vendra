<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Infrastructure\Models\PaymentIntent;

/**
 * A new intent, with the secret the customer's browser needs to confirm it.
 * The secret is handed out once and never stored.
 */
final readonly class StartedPayment
{
    public function __construct(
        public PaymentIntent $intent,
        public ?string $clientSecret,
    ) {}
}
