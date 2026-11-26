<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Application\Actions;

/**
 * What a client may set on a subscription. On update, null means unchanged.
 */
final readonly class SubscriptionData
{
    /**
     * @param  list<string>|null  $eventTypes
     */
    public function __construct(
        public ?string $url = null,
        public ?array $eventTypes = null,
        public ?string $description = null,
        public bool $updatesDescription = false,
    ) {}
}
