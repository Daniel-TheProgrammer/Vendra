<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Pricing;

use App\Shared\Domain\Money\Money;

final readonly class OrderTotals
{
    public Money $total;

    public function __construct(
        public Money $subtotal,
        public Money $tax,
    ) {
        $this->total = $subtotal->add($tax);
    }
}
