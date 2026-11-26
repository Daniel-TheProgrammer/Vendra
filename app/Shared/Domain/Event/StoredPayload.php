<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use UnexpectedValueException;

/**
 * Typed reads of a stored event payload, for DomainEvent::fromStored(). A
 * payload that does not hold what its event wrote is a bug, so a missing or
 * mistyped field fails loudly instead of rebuilding a wrong event.
 */
final readonly class StoredPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    public function string(string $field): string
    {
        $value = $this->payload[$field] ?? null;

        return is_string($value) ? $value : throw $this->invalid($field, 'a string');
    }

    public function nullableString(string $field): ?string
    {
        $value = $this->payload[$field] ?? null;

        return $value === null || is_string($value) ? $value : throw $this->invalid($field, 'a string or null');
    }

    public function int(string $field): int
    {
        $value = $this->payload[$field] ?? null;

        return is_int($value) ? $value : throw $this->invalid($field, 'an integer');
    }

    public function currency(string $field = 'currency'): Currency
    {
        return Currency::tryFrom($this->string($field)) ?? throw $this->invalid($field, 'a currency code');
    }

    /**
     * An amount stored as "{prefix}_minor" in the currency stored as "currency".
     */
    public function money(string $prefix): Money
    {
        return Money::of($this->int($prefix.'_minor'), $this->currency());
    }

    private function invalid(string $field, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('Stored event payload field "%s" must be %s.', $field, $expected));
    }
}
