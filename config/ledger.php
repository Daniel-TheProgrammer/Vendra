<?php

declare(strict_types=1);

return [
    // Currency a new tenant's chart of accounts is opened in. Accounts in
    // other currencies open automatically on their first posting.
    'default_currency' => env('LEDGER_DEFAULT_CURRENCY', 'USD'),
];
