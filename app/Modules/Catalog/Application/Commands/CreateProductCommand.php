<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use App\Modules\Catalog\Domain\ProductStatus;

final readonly class CreateProductCommand
{
    /**
     * @param  non-empty-list<VariantData>  $variants
     */
    public function __construct(
        public string $tenantId,
        public string $name,
        public ?string $description,
        public ProductStatus $status,
        public array $variants,
    ) {}
}
