<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Application\Services\ProductLock;
use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Domain\Price;
use App\Modules\Catalog\Domain\PriceWindow;
use App\Modules\Catalog\Domain\PriceWindowOverlaps;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Adds a price that applies only within a window: a sale, or an increase
 * announced in advance. While the window is open it takes precedence over
 * the base price. Windows of one currency may not overlap.
 *
 * Returns the product (new version) and the id of the scheduled price.
 */
final readonly class SchedulePrice
{
    /** PostgreSQL exclusion_violation, raised by variant_prices_no_overlap. */
    private const EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private TransactionRunner $transaction,
        private ProductLock $lock,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    /**
     * @return array{Product, string}
     *
     * @throws PriceWindowOverlaps
     */
    public function __invoke(string $variantId, Money $amount, PriceWindow $window, ?int $expectedVersion = null): array
    {
        if ($window->isAlways()) {
            throw new InvalidArgumentException('A scheduled price needs a start, an end or both; use SetVariantPrice for the base price.');
        }

        if ($window->hasEndedBy($this->clock->now())) {
            throw new InvalidArgumentException('A scheduled price cannot end in the past.');
        }

        /** @var array{Product, string} $result */
        $result = $this->transaction->run(function () use ($variantId, $amount, $window, $expectedVersion): array {
            $variant = ProductVariant::query()->whereKey($variantId)->first()
                ?? throw EntityNotFound::withId('Variant', $variantId);

            // The product lock serialises every price write for this product,
            // so the check below cannot race; the database constraint is the
            // backstop for anything that bypasses it.
            $product = $this->lock->acquireEditable($variant->product_id, $expectedVersion);
            $variant->priceList()->assertCanSchedule(new Price($amount, $window));

            try {
                $row = VariantPrice::query()->create([
                    'variant_id' => $variant->id,
                    'currency' => $amount->currency,
                    'amount' => $amount->amount,
                    'valid_from' => $this->utc($window->from),
                    'valid_to' => $this->utc($window->to),
                ]);
            } catch (QueryException $exception) {
                throw $exception->getCode() === self::EXCLUSION_VIOLATION ? PriceWindowOverlaps::detectedByDatabase() : $exception;
            }

            $product->touchVersion();
            $product->save();

            return [$product, $row->id];
        });

        [$product] = $result;
        $this->events->dispatch(new PriceChanged($product->tenant_id, $product->id, $product->version, $this->clock->now(), $variantId, $amount->currency));

        return $result;
    }

    /**
     * Eloquent writes timestamps without their offset, so they must be UTC
     * (the connection's time zone) before they reach it.
     */
    private function utc(?DateTimeInterface $instant): ?CarbonImmutable
    {
        return $instant instanceof DateTimeInterface ? CarbonImmutable::instance($instant)->utc() : null;
    }
}
