<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Events;

use App\Shared\Domain\Money\Currency;
use Carbon\CarbonImmutable;

/**
 * A base or scheduled price of one variant was set, scheduled or removed.
 * Like every catalog event it names what changed, not the new amounts:
 * consumers resolve the current price themselves.
 */
final readonly class PriceChanged extends ProductEvent
{
    public function __construct(
        string $tenantId,
        string $productId,
        int $version,
        CarbonImmutable $occurredAt,
        public string $variantId,
        public Currency $currency,
    ) {
        parent::__construct($tenantId, $productId, $version, $occurredAt);
    }

    public function eventName(): string
    {
        return 'catalog.price_changed';
    }

    public function payload(): array
    {
        return [
            ...parent::payload(),
            'variant_id' => $this->variantId,
            'currency' => $this->currency->value,
        ];
    }
}
