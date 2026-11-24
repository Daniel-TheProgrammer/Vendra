<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

use App\Modules\Orders\Domain\ShippingAddress;
use App\Shared\Domain\Money\Currency;
use InvalidArgumentException;

/**
 * The customer is named by id, or by e-mail (found or created).
 */
final readonly class PlaceOrderCommand
{
    public const MAX_LINES = 100;

    /**
     * @param  non-empty-list<OrderLineInput>  $lines
     */
    public function __construct(
        public Currency $currency,
        public array $lines,
        public ?string $customerId = null,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?ShippingAddress $shippingAddress = null,
        public ?string $actorId = null,
    ) {
        if (($customerId === null) === ($customerEmail === null)) {
            throw new InvalidArgumentException('Name the customer by id or by e-mail, not both or neither.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException(sprintf('An order may have at most %d lines.', self::MAX_LINES));
        }
    }
}
