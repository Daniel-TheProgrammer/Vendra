<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Queries;

use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindPaymentIntent
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $intentId): PaymentIntent
    {
        return PaymentIntent::query()->whereKey($intentId)->first() ?? throw EntityNotFound::withId('Payment intent', $intentId);
    }
}
