<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use App\Modules\Catalog\Domain\ProductStatus;

/**
 * A partial update. Null means "leave unchanged", except for the description,
 * which can be cleared: $updatesDescription says whether it was sent at all.
 */
final readonly class UpdateProductCommand
{
    public function __construct(
        public string $productId,
        public ?int $expectedVersion = null,
        public ?string $name = null,
        public ?ProductStatus $status = null,
        public bool $updatesDescription = false,
        public ?string $description = null,
    ) {}
}
