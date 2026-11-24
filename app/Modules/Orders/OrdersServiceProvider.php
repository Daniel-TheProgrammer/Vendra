<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Orders\Domain\Pricing\FlatRateTax;
use App\Modules\Orders\Domain\Pricing\TaxStrategy;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Orders';
    }

    public function register(): void
    {
        $this->app->singleton(TaxStrategy::class, static fn (Application $app): TaxStrategy => new FlatRateTax(
            $app->make(Config::class)->integer('orders.tax_rate_basis_points', 0),
        ));
    }
}
