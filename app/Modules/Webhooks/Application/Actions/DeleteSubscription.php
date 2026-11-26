<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Actions;

use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Domain\Exception\EntityNotFound;

/**
 * Unsubscribes for good. Deliveries already made stay in the delivery log
 * until they are pruned; nothing more is sent.
 */
final readonly class DeleteSubscription
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $id): void
    {
        $deleted = WebhookSubscription::query()->whereKey($id)->delete();

        if ($deleted === 0) {
            throw EntityNotFound::withId('Webhook subscription', $id);
        }
    }
}
