<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use App\Modules\Catalog\Domain\AdjustmentReason;

final readonly class AdjustStockCommand
{
    public function __construct(
        public string $variantId,
        public int $delta,
        public AdjustmentReason $reason,
        public ?string $note = null,
        public ?string $actorId = null,
    ) {}
}
