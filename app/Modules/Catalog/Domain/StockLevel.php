<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

/**
 * Stock of one variant: units physically on hand, and how many of those are
 * promised to orders that have not shipped yet. Only the rest can be sold.
 *
 * Immutable: every operation returns the next level or refuses.
 */
final readonly class StockLevel
{
    public function __construct(
        public string $variantId,
        public int $onHand,
        public int $reserved,
    ) {
        if ($onHand < 0 || $reserved < 0 || $reserved > $onHand) {
            throw new InvalidArgumentException(sprintf('Invalid stock level for %s: %d on hand, %d reserved.', $variantId, $onHand, $reserved));
        }
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }

    /**
     * Receives (positive) or writes off (negative) physical units. Reserved
     * units cannot be written off: release the reservation first.
     */
    public function adjust(int $delta): self
    {
        $this->assertNonZero($delta);

        if ($delta < 0 && -$delta > $this->available()) {
            throw InsufficientStock::for($this->variantId, $this->available(), -$delta);
        }

        return new self($this->variantId, $this->onHand + $delta, $this->reserved);
    }

    public function reserve(int $quantity): self
    {
        $this->assertPositive($quantity);

        if ($quantity > $this->available()) {
            throw InsufficientStock::for($this->variantId, $this->available(), $quantity);
        }

        return new self($this->variantId, $this->onHand, $this->reserved + $quantity);
    }

    public function release(int $quantity): self
    {
        $this->assertPositive($quantity);

        if ($quantity > $this->reserved) {
            throw StockNotReserved::for($this->variantId, $this->reserved, $quantity);
        }

        return new self($this->variantId, $this->onHand, $this->reserved - $quantity);
    }

    private function assertNonZero(int $quantity): void
    {
        if ($quantity === 0) {
            throw new InvalidArgumentException('A stock adjustment must change the quantity.');
        }
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }
    }
}
