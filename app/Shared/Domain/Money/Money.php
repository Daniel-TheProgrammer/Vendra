<?php

declare(strict_types=1);

namespace App\Shared\Domain\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact amount of money: an integer number of minor units plus a currency.
 *
 * Floats never appear here. Division is only exposed through operations that
 * distribute every minor unit exactly (allocate) or round explicitly (ratio).
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses a decimal string such as "12.34" or "-0.5" in the currency's minor unit.
     */
    public static function fromDecimal(string $decimal, Currency $currency): self
    {
        $scale = $currency->minorUnit();
        $pattern = $scale === 0 ? '/^(-)?(\d+)$/' : sprintf('/^(-)?(\d+)(?:\.(\d{1,%d}))?$/', $scale);

        if (preg_match($pattern, $decimal, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid %s amount.', $decimal, $currency->value));
        }

        $major = (int) $matches[2];
        $minor = isset($matches[3]) ? (int) str_pad($matches[3], $scale, '0') : 0;
        $amount = $major * $currency->subunitFactor() + $minor;

        return new self($matches[1] === '-' ? -$amount : $amount, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $multiplier): self
    {
        return new self($this->amount * $multiplier, $this->currency);
    }

    /**
     * Multiplies by numerator/denominator, rounding half away from zero.
     * Use it for percentages: ratio(825, 10000) applies 8.25%.
     */
    public function ratio(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Denominator must not be zero.');
        }

        $product = $this->amount * $numerator;
        $quotient = intdiv($product, $denominator);
        $remainder = $product % $denominator;

        if (abs($remainder) * 2 >= abs($denominator)) {
            $quotient += ($product <=> 0) * ($denominator <=> 0);
        }

        return new self($quotient, $this->currency);
    }

    /**
     * Splits the amount by the given ratios without losing a single minor unit.
     * Remainders go to the earliest parts, so 100 split 1:1:1 is 34, 33, 33.
     *
     * @return list<self>
     */
    public function allocate(int ...$ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('At least one ratio is required.');
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw new InvalidArgumentException('Ratios must not be negative.');
            }
        }

        $total = array_sum($ratios);

        if ($total === 0) {
            throw new InvalidArgumentException('Ratios must not all be zero.');
        }

        $shares = [];
        $remainder = $this->amount;

        foreach ($ratios as $ratio) {
            $share = intdiv($this->amount * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        $step = $remainder <=> 0;

        for ($i = 0; $remainder !== 0; $i = ($i + 1) % count($shares)) {
            if ($ratios[$i] === 0) {
                continue;
            }

            $shares[$i] += $step;
            $remainder -= $step;
        }

        return array_values(array_map(fn (int $share): self => new self($share, $this->currency), $shares));
    }

    /**
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Parts must be at least one.');
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->amount), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    /**
     * Decimal representation in major units, e.g. "12.34" or "-0.05".
     */
    public function toDecimal(): string
    {
        $scale = $this->currency->minorUnit();

        if ($scale === 0) {
            return (string) $this->amount;
        }

        $factor = $this->currency->subunitFactor();
        $absolute = abs($this->amount);
        $sign = $this->amount < 0 ? '-' : '';

        return sprintf('%s%d.%0'.$scale.'d', $sign, intdiv($absolute, $factor), $absolute % $factor);
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency->value,
        ];
    }

    public function __toString(): string
    {
        return $this->currency->value.' '.$this->toDecimal();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
