<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Queries;

use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * An order's money facts, for modules that account for it (Ledger) or
 * report on it, without exposing the order's persistence model.
 */
final readonly class OrderSummary
{
    public function __construct(
        public string $id,
        public int $number,
        public OrderStatus $status,
        public Money $subtotal,
        public Money $tax,
        public Money $total,
        public CarbonImmutable $placedAt,
    ) {}
}
