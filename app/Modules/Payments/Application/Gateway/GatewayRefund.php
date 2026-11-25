<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

use App\Modules\Payments\Domain\RefundStatus;
use App\Shared\Domain\Money\Money;

/**
 * A provider's refund, translated into our terms.
 */
final readonly class GatewayRefund
{
    public function __construct(
        public string $reference,
        /** The provider's payment intent the refund returns money from. */
        public string $paymentRef,
        public RefundStatus $status,
        public Money $amount,
        /** Our refund id, when we asked for it (provider metadata). */
        public ?string $ourId = null,
        public ?string $failureCode = null,
    ) {}
}
