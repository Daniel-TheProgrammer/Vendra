<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\Money\Currency;
use DateTimeInterface;

/**
 * Every price one variant has, and the rule for which one applies when:
 *
 *   1. a scheduled price whose window contains the instant, otherwise
 *   2. the base price, otherwise
 *   3. none: the variant is not sellable in that currency at that instant.
 *
 * Per currency there is at most one base price and scheduled windows never
 * overlap, so the answer is never ambiguous. The database enforces the same
 * invariants; checking here gives a precise error before a write is tried.
 */
final readonly class PriceList
{
    /**
     * @param  list<Price>  $prices
     */
    public function __construct(private array $prices) {}

    public function effectiveAt(Currency $currency, DateTimeInterface $instant): ?Price
    {
        $base = null;

        foreach ($this->inCurrency($currency) as $price) {
            if (! $price->isScheduled()) {
                $base = $price;
            } elseif ($price->window->contains($instant)) {
                return $price;
            }
        }

        return $base;
    }

    /**
     * The price in effect for every currency that has one at the instant.
     *
     * @return list<Price>
     */
    public function allEffectiveAt(DateTimeInterface $instant): array
    {
        $currencies = [];

        foreach ($this->prices as $price) {
            $currencies[$price->amount->currency->value] = $price->amount->currency;
        }

        ksort($currencies);

        $effective = [];

        foreach ($currencies as $currency) {
            $price = $this->effectiveAt($currency, $instant);

            if ($price instanceof Price) {
                $effective[] = $price;
            }
        }

        return $effective;
    }

    /**
     * Scheduled prices that apply now or later, soonest first.
     *
     * @return list<Price>
     */
    public function upcomingScheduledAt(DateTimeInterface $instant): array
    {
        $upcoming = array_values(array_filter(
            $this->prices,
            static fn (Price $price): bool => $price->isScheduled() && ! $price->window->hasEndedBy($instant),
        ));

        usort($upcoming, static fn (Price $a, Price $b): int => [$a->window->from, $a->amount->currency->value] <=> [$b->window->from, $b->amount->currency->value]);

        return $upcoming;
    }

    /**
     * @throws PriceWindowOverlaps
     */
    public function assertCanSchedule(Price $candidate): void
    {
        foreach ($this->inCurrency($candidate->amount->currency) as $existing) {
            if ($existing->isScheduled() && $existing->window->overlaps($candidate->window)) {
                throw PriceWindowOverlaps::with($existing);
            }
        }
    }

    /**
     * @return list<Price>
     */
    private function inCurrency(Currency $currency): array
    {
        return array_values(array_filter($this->prices, static fn (Price $price): bool => $price->amount->currency === $currency));
    }
}
