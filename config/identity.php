<?php

declare(strict_types=1);

return [

    /*
     * Lifetime of personal access tokens issued at login, registration and
     * tenant switch, and the default for tokens created through the API.
     */
    'token_ttl_days' => (int) env('IDENTITY_TOKEN_TTL_DAYS', 30),

    /*
     * Upper bound a client may request when creating a token explicitly.
     */
    'token_max_ttl_days' => (int) env('IDENTITY_TOKEN_MAX_TTL_DAYS', 365),

    /*
     * Login attempts allowed per minute for one e-mail address from one IP.
     */
    'login_attempts_per_minute' => (int) env('IDENTITY_LOGIN_ATTEMPTS_PER_MINUTE', 5),

    'api_keys' => [

        /*
         * After a rotation the previous key keeps working this long, so running
         * deployments can switch over without downtime.
         */
        'rotation_grace_hours' => (int) env('IDENTITY_API_KEY_ROTATION_GRACE_HOURS', 24),

        /*
         * Creators are e-mailed this many days before a key expires.
         */
        'expiry_warning_days' => (int) env('IDENTITY_API_KEY_EXPIRY_WARNING_DAYS', 7),

        'max_ttl_days' => (int) env('IDENTITY_API_KEY_MAX_TTL_DAYS', 730),

        /*
         * last_used_at is refreshed at most this often per key, to avoid a write
         * on every request.
         */
        'touch_interval_seconds' => 60,
    ],

];
