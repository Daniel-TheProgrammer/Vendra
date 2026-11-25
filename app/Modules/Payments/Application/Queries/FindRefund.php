<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Queries;

use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindRefund
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $refundId): Refund
    {
        return Refund::query()->whereKey($refundId)->first() ?? throw EntityNotFound::withId('Refund', $refundId);
    }
}
