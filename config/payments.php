<?php

declare(strict_types=1);

return [
    // "stripe" talks to Stripe (test mode only, see docs/payments.md);
    // "fake" keeps intents in the cache, for tests and offline work.
    'gateway' => env('PAYMENTS_GATEWAY', 'fake'),

    'stripe' => [
        // The platform's test-mode secret key; tenants' accounts are reached
        // through it with the Stripe-Account header. A live key is refused.
        'secret' => env('STRIPE_SECRET'),
        'base_url' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
        'timeout' => (int) env('STRIPE_TIMEOUT', 10),
    ],
];
