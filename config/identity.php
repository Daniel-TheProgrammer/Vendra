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

];
