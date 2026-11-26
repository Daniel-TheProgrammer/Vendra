<?php

declare(strict_types=1);

return [
    /*
     * What happens right after a transaction that recorded events commits:
     *
     *   queue   a PublishOutbox job is queued, so listeners run within
     *           milliseconds without delaying the request (production);
     *   inline  the relay runs in the same process before the request ends
     *           (tests: listeners' effects are visible to the next assertion);
     *   off     nothing; only the outbox:relay process publishes.
     *
     * Whatever the mode, the outbox:relay process sweeps up anything left.
     */
    'after_commit' => env('OUTBOX_AFTER_COMMIT', 'queue'),

    // Messages taken per relay pass.
    'batch_size' => (int) env('OUTBOX_BATCH_SIZE', 100),

    // A message whose listeners keep failing is retried with these delays
    // (seconds), then marked dead and logged as critical.
    'backoff' => [5, 30, 120, 600, 1800, 3600, 3600, 3600],

    // Published messages are kept this long: they back GET /events.
    'retention_days' => (int) env('OUTBOX_RETENTION_DAYS', 30),
];
