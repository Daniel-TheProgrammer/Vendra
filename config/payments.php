<?php

declare(strict_types=1);

return [
    // "stripe" talks to Stripe (test mode only, see docs/payments.md);
    // "fake" keeps intents in the cache, for tests and offline work.
    'gateway' => env('PAYMENTS_GATEWAY', 'fake'),
];
