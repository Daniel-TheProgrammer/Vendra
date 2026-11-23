<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\ConflictException;

final class InsufficientStock extends ConflictException
{
    private string $variantId = '';

    private int $available = 0;

    private int $requested = 0;

    public static function for(string $variantId, int $available, int $requested): self
    {
        $exception = new self(sprintf('Only %d unit(s) of variant %s are available; %d requested.', $available, $variantId, $requested));
        $exception->variantId = $variantId;
        $exception->available = $available;
        $exception->requested = $requested;

        return $exception;
    }

    public function type(): string
    {
        return 'insufficient-stock';
    }

    public function title(): string
    {
        return 'Insufficient stock';
    }

    public function extensions(): array
    {
        return [
            'variant_id' => $this->variantId,
            'available' => $this->available,
            'requested' => $this->requested,
        ];
    }
}
