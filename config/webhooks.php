<?php

declare(strict_types=1);

return [
    // Subscriptions per tenant: bounds the fan-out of every event.
    'max_subscriptions' => (int) env('WEBHOOKS_MAX_SUBSCRIPTIONS', 25),

    // A subscription is paused after this many consecutive failed deliveries.
    'pause_after_failures' => (int) env('WEBHOOKS_PAUSE_AFTER_FAILURES', 10),

    // Seconds before each retry of a failed delivery: 1m, 5m, 30m, 2h, 12h.
    // After the last, the delivery is dead (it can still be redelivered).
    'retry_schedule' => [60, 300, 1800, 7200, 43200],

    // Seconds a receiver has to answer.
    'timeout' => 5,

    // Hours the previous secret still signs deliveries after a rotation.
    'secret_overlap_hours' => 24,
];
