<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Commands;

use App\Shared\Domain\Money\Money;
use InvalidArgumentException;

final readonly class VariantData
{
    /**
     * @param  array<string, string>  $options  e.g. ["size" => "M", "colour" => "red"]
     * @param  list<Money>  $prices  at most one per currency
     */
    public function __construct(
        public string $sku,
        public ?string $name = null,
        public array $options = [],
        public array $prices = [],
    ) {
        $currencies = array_map(static fn (Money $price): string => $price->currency->value, $prices);

        if (count($currencies) !== count(array_unique($currencies))) {
            throw new InvalidArgumentException(sprintf('Variant %s has more than one price in the same currency.', $sku));
        }
    }
}
