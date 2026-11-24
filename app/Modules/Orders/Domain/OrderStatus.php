<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

/**
 * The order lifecycle (docs/orders-state-machine.md):
 *
 *   pending ──► awaiting_payment ──► paid ──► fulfilled
 *      │               │               │          │
 *      └──► cancelled ◄┘               └──────────┴──► refunded
 *
 * Every status change goes through transitionTo(), which refuses anything
 * not drawn above.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::AwaitingPayment, self::Cancelled],
            self::AwaitingPayment => [self::Paid, self::Cancelled],
            self::Paid => [self::Fulfilled, self::Refunded],
            self::Fulfilled => [self::Refunded],
            self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @throws IllegalTransition
     */
    public function transitionTo(self $next): self
    {
        return $this->canTransitionTo($next) ? $next : throw IllegalTransition::between($this, $next);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statuses in which the order's stock is held for it.
     */
    public function holdsStock(): bool
    {
        return in_array($this, [self::Pending, self::AwaitingPayment, self::Paid], true);
    }

    /**
     * The parcel has not left yet, so where it goes may still change.
     */
    public function allowsShippingChanges(): bool
    {
        return $this->holdsStock();
    }
}
