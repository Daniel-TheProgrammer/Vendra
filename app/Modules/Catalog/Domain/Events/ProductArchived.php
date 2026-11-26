<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Event\StoredPayload;
use Carbon\CarbonImmutable;

final readonly class ProductArchived extends ProductEvent
{
    public const NAME = 'catalog.product_archived';

    public function eventName(): string
    {
        return self::NAME;
    }

    public static function fromStored(string $tenantId, CarbonImmutable $occurredAt, array $payload): static
    {
        $stored = new StoredPayload($payload);

        return new self($tenantId, $stored->string('product_id'), $stored->int('version'), $occurredAt);
    }
}
