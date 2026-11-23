<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use InvalidArgumentException;

final readonly class StockLine
{
    public function __construct(
        public string $variantId,
        public int $quantity,
    ) {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Stock line quantities must be positive.');
        }
    }

    /**
     * Sums lines for the same variant, so an order listing a variant twice
     * reserves the total once.
     *
     * @param  list<self>  $lines
     * @return array<string, int> quantity by variant id
     */
    public static function totals(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $totals[$line->variantId] = ($totals[$line->variantId] ?? 0) + $line->quantity;
        }

        return $totals;
    }
}
