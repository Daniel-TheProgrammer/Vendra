<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Delivery;

use App\Modules\Webhooks\Domain\DeliveryStatus;
use App\Modules\Webhooks\Infrastructure\Models\WebhookDelivery;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Sends a delivery again, with the same body and event id (so a receiver
 * that already processed it can tell), and a fresh retry schedule. Works
 * from any state; the usual case is a dead delivery after the receiver
 * was fixed.
 */
final readonly class Redeliver
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
    ) {}

    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $deliveryId): WebhookDelivery
    {
        return $this->transaction->run(function () use ($deliveryId): WebhookDelivery {
            $delivery = WebhookDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first() ?? throw EntityNotFound::withId('Webhook delivery', $deliveryId);

            $delivery->status = DeliveryStatus::Pending;
            $delivery->attempts = 0;
            $delivery->next_attempt_at = $this->clock->now();
            $delivery->error = null;
            $delivery->save();

            return $delivery;
        });
    }
}
