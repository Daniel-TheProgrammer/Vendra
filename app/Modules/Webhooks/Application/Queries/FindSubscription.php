<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Queries;

use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Domain\Exception\EntityNotFound;

final readonly class FindSubscription
{
    /**
     * @throws EntityNotFound
     */
    public function __invoke(string $id): WebhookSubscription
    {
        return WebhookSubscription::query()->whereKey($id)->first() ?? throw EntityNotFound::withId('Webhook subscription', $id);
    }
}
