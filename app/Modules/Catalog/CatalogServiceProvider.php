<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Shared\Infrastructure\Module\ModuleServiceProvider;

final class CatalogServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Catalog';
    }
}
