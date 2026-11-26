<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Actions;

use App\Modules\Webhooks\Application\Services\SigningSecrets;
use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Issues a new signing secret. For $overlapHours, deliveries carry a
 * signature under each secret, so the receiver can switch to the new one at
 * its own pace; 0 retires the old one at once (for a leaked secret).
 */
final readonly class RotateSigningSecret
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
    ) {}

    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $id, int $overlapHours): WebhookSubscription
    {
        return $this->transaction->run(function () use ($id, $overlapHours): WebhookSubscription {
            $subscription = WebhookSubscription::query()->whereKey($id)->lockForUpdate()->first() ?? throw EntityNotFound::withId('Webhook subscription', $id);

            $subscription->previous_secret = $overlapHours > 0 ? $subscription->secret : null;
            $subscription->previous_secret_expires_at = $overlapHours > 0 ? $this->clock->now()->addHours($overlapHours) : null;
            $subscription->secret = SigningSecrets::generate();
            $subscription->version++;
            $subscription->save();

            return $subscription;
        });
    }
}
