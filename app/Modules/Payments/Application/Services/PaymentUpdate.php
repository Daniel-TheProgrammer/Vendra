<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * News about an intent: from a gateway response, a webhook, or a decision
 * of ours (cancelling with the order).
 */
final readonly class PaymentUpdate
{
    public function __construct(
        public PaymentIntentStatus $status,
        /** When the provider reported this; null for our own, synchronous calls. */
        public ?CarbonImmutable $reportedAt = null,
        public ?Money $fee = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public static function cancelled(): self
    {
        return new self(PaymentIntentStatus::Cancelled);
    }
}
