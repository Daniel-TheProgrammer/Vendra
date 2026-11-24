<?php

declare(strict_types=1);

return [
    // How long a key and its stored response are kept (ADR 0007).
    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),

    // Lock held while the first request with a key executes. Must exceed the
    // slowest request; a lock that expires early lets a retry run concurrently.
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 60),

    // Cache store for the locks: Redis in every deployed environment (SET NX
    // with a TTL). null uses the default store.
    'lock_store' => env('IDEMPOTENCY_LOCK_STORE'),

    // Sent as Retry-After when a request arrives while its key is in flight.
    'retry_after_seconds' => 1,
];
