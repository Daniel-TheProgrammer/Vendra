<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

final readonly class ProductArchived extends ProductEvent
{
    public function eventName(): string
    {
        return 'catalog.product_archived';
    }
}
