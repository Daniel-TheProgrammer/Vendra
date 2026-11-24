<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Queries;

/**
 * What another module may know about a variant it sells: enough to
 * snapshot onto an order line, nothing it could write back.
 */
final readonly class SellableVariant
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $sku,
        /** Product name, plus the variant name when it has one: "Organic Tee / Red, M". */
        public string $name,
        public bool $isSellable,
    ) {}
}
