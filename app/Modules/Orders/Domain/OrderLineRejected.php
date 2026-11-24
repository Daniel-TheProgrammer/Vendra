<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use App\Shared\Domain\Exception\DomainException;

/**
 * A line names something that cannot be sold as asked. Nothing is placed.
 */
final class OrderLineRejected extends DomainException
{
    private int $lineNumber = 0;

    private string $variantId = '';

    public static function unknownVariant(int $line, string $variantId): self
    {
        return self::make($line, $variantId, 'Line %d: variant %s does not exist.');
    }

    public static function notForSale(int $line, string $variantId): self
    {
        return self::make($line, $variantId, 'Line %d: variant %s belongs to a product that is not active.');
    }

    public static function notPricedIn(int $line, string $variantId, string $currency): self
    {
        return self::make($line, $variantId, 'Line %d: variant %s has no '.$currency.' price right now.');
    }

    private static function make(int $line, string $variantId, string $format): self
    {
        $exception = new self(sprintf($format, $line, $variantId));
        $exception->lineNumber = $line;
        $exception->variantId = $variantId;

        return $exception;
    }

    public function type(): string
    {
        return 'order-line-rejected';
    }

    public function title(): string
    {
        return 'Order line cannot be sold';
    }

    /**
     * @return array{line: int, variant_id: string}
     */
    public function extensions(): array
    {
        return ['line' => $this->lineNumber, 'variant_id' => $this->variantId];
    }
}
