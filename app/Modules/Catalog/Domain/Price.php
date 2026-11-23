<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Money\Money;

/**
 * An amount and the window it applies to. A base price has the "always"
 * window; a scheduled price (a sale, an announced increase) has at least one
 * bound.
 */
final readonly class Price
{
    public function __construct(
        public Money $amount,
        public PriceWindow $window,
        public ?string $id = null,
    ) {}

    public function isScheduled(): bool
    {
        return ! $this->window->isAlways();
    }
}
