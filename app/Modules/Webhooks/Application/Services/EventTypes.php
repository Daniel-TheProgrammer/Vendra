<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Services;

use App\Modules\Webhooks\Infrastructure\Models\WebhookSubscription;
use App\Shared\Infrastructure\Outbox\EventRegistry;

/**
 * The events a tenant can subscribe to: every domain event the modules
 * publish through the outbox (ADR 0010).
 */
final readonly class EventTypes
{
    public function __construct(
        private EventRegistry $registry,
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->registry->all());
    }

    /**
     * @param  list<string>  $types
     * @return list<string> the types that are neither an event nor "*"
     */
    public function unknown(array $types): array
    {
        return array_values(array_filter(
            $types,
            fn (string $type): bool => $type !== WebhookSubscription::ALL_EVENTS && ! $this->registry->has($type),
        ));
    }
}
