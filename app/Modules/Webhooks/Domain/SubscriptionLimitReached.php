<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class SubscriptionLimitReached extends ConflictException
{
    public static function of(int $limit): self
    {
        return new self(sprintf('A tenant can have at most %d webhook subscriptions; delete one first.', $limit));
    }

    public function type(): string
    {
        return 'webhook-subscription-limit';
    }

    public function title(): string
    {
        return 'Too many webhook subscriptions';
    }
}
