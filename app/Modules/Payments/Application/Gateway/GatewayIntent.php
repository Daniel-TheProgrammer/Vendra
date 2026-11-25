<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

use App\Modules\Payments\Application\Services\PaymentUpdate;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A provider's intent, translated into our terms.
 */
final readonly class GatewayIntent
{
    public function __construct(
        public string $reference,
        public PaymentIntentStatus $status,
        public Money $amount,
        /** Handed to the customer's browser to confirm the payment; only on creation. */
        public ?string $clientSecret = null,
        /** What the provider kept, once the money is captured. */
        public ?Money $fee = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    public function toUpdate(?CarbonImmutable $reportedAt = null): PaymentUpdate
    {
        return new PaymentUpdate($this->status, $reportedAt, $this->fee, $this->failureCode, $this->failureMessage);
    }
}
