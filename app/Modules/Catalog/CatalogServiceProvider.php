<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Domain\Events\StockAdjusted;
use App\Shared\Infrastructure\Module\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }

    protected function moduleEvents(): array
    {
        return [ProductCreated::class, ProductUpdated::class, ProductArchived::class, PriceChanged::class, StockAdjusted::class];
    }
}
