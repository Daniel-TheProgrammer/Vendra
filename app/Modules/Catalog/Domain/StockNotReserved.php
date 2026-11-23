<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\ConflictException;

/**
 * Releasing more than is reserved means the caller's bookkeeping is wrong
 * (for example an order released twice). Refused rather than clamped.
 */
final class StockNotReserved extends ConflictException
{
    private string $variantId = '';

    private int $reserved = 0;

    public static function for(string $variantId, int $reserved, int $requested): self
    {
        $exception = new self(sprintf('Cannot release %d unit(s) of variant %s: only %d reserved.', $requested, $variantId, $reserved));
        $exception->variantId = $variantId;
        $exception->reserved = $reserved;

        return $exception;
    }

    public function type(): string
    {
        return 'stock-not-reserved';
    }

    public function title(): string
    {
        return 'Stock not reserved';
    }

    public function extensions(): array
    {
        return ['variant_id' => $this->variantId, 'reserved' => $this->reserved];
    }
}
