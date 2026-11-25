<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

/**
 * A refund's lifecycle:
 *
 *   pending ──► succeeded
 *      └──────► failed
 *
 * Card refunds usually succeed at once; some methods take days, and a few
 * fail (a closed account), in which case the money stays with the merchant.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * @throws IllegalRefundTransition
     */
    public function transitionTo(self $next): self
    {
        return $this->canTransitionTo($next) ? $next : throw IllegalRefundTransition::between($this, $next);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Counts against what may still be refunded.
     */
    public function holdsAmount(): bool
    {
        return $this !== self::Failed;
    }
}
