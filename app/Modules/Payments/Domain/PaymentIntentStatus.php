<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * A payment intent's lifecycle (docs/payments.md):
 *
 *   requires_payment ──► processing ──► succeeded
 *          │                 │
 *          ├──► failed ◄─────┤
 *          └──► cancelled ◄──┘
 *
 * "processing" means the customer has authorised the payment and the money
 * is held, waiting to be captured. A card can be captured straight from
 * requires_payment when the gateway authorises and captures in one step.
 * failed is final: the order stays awaiting payment and a new intent is
 * created for the next attempt.
 */
enum PaymentIntentStatus: string
{
    case RequiresPayment = 'requires_payment';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::RequiresPayment => [self::Processing, self::Succeeded, self::Failed, self::Cancelled],
            self::Processing => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @throws IllegalPaymentTransition
     */
    public function transitionTo(self $next): self
    {
        return $this->canTransitionTo($next) ? $next : throw IllegalPaymentTransition::between($this, $next);
    }

    /**
     * Still in flight: money may yet move.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::RequiresPayment, self::Processing], true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::RequiresPayment->value, self::Processing->value];
    }
}
