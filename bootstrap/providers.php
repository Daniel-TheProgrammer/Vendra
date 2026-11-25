<?php

declare(strict_types=1);

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Ledger\LedgerServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Tenancy\TenancyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Shared\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,

    // Modules, in dependency order.
    TenancyServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    OrdersServiceProvider::class,
    LedgerServiceProvider::class,
    PaymentsServiceProvider::class,
];
