<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Gateway;

use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * One movement on the tenant's balance at the provider: what the provider
 * says actually happened to the money. Charges are positive, refunds
 * negative; net is what reached (or left) the balance after fees.
 */
final readonly class GatewayBalanceTransaction
{
    public const CHARGE = 'charge';

    public const REFUND = 'refund';

    public function __construct(
        public string $id,
        /** charge, refund, or the provider's own type for anything else */
        public string $type,
        public Money $amount,
        public Money $fee,
        public Money $net,
        /** The provider's payment intent the movement belongs to, when it has one. */
        public ?string $paymentRef,
        public CarbonImmutable $createdAt,
    ) {}
}
