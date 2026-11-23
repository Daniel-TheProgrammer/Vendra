<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class ProductIsArchived extends ConflictException
{
    public static function create(string $productId): self
    {
        return new self(sprintf('Product %s is archived and can no longer be changed.', $productId));
    }

    public function type(): string
    {
        return 'product-archived';
    }

    public function title(): string
    {
        return 'Product archived';
    }
}
