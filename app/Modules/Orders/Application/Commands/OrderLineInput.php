<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

use InvalidArgumentException;

final readonly class OrderLineInput
{
    public const MAX_QUANTITY = 10_000;

    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf('A line quantity must be between 1 and %d.', self::MAX_QUANTITY));
        }
    }
}
