<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Exception\ConflictException;
use DateTimeInterface;

final class PriceWindowOverlaps extends ConflictException
{
    /** @var array<string, string|null> */
    private array $conflict = [];

    public static function with(Price $existing): self
    {
        $exception = new self(sprintf(
            'The window overlaps a scheduled %s price that already covers part of it.',
            $existing->amount->currency->value,
        ));
        $exception->conflict = [
            'conflicting_price_id' => $existing->id,
            'conflicting_valid_from' => $existing->window->from?->format(DateTimeInterface::RFC3339),
            'conflicting_valid_to' => $existing->window->to?->format(DateTimeInterface::RFC3339),
        ];

        return $exception;
    }

    public static function detectedByDatabase(): self
    {
        return new self('The window overlaps a scheduled price that was added concurrently.');
    }

    public function type(): string
    {
        return 'price-window-overlap';
    }

    public function title(): string
    {
        return 'Scheduled prices overlap';
    }

    public function extensions(): array
    {
        return $this->conflict;
    }
}
