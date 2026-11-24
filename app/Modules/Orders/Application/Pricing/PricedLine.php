<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Pricing;

use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

final readonly class PricedLine
{
    public Money $lineTotal;

    public function __construct(
        public string $variantId,
        public string $sku,
        public string $name,
        public Money $unitPrice,
        public int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A line quantity must be positive.');
        }

        $this->lineTotal = $unitPrice->multiply($quantity);
    }
}
