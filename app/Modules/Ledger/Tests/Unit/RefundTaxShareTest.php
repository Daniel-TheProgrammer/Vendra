<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Application\Postings\PaymentPostings;
use App\Modules\Orders\Application\Queries\OrderSummary;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Payments\Domain\Events\RefundIssued;
use App\Modules\Payments\Domain\Settlement;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundTaxShareTest extends TestCase
{
    private function order(int $subtotal, int $tax): OrderSummary
    {
        return new OrderSummary('order-1', 1001, OrderStatus::Paid, Money::of($subtotal, Currency::USD), Money::of($tax, Currency::USD), Money::of($subtotal + $tax, Currency::USD), CarbonImmutable::now());
    }

    private function refund(int $amount, int $previously): RefundIssued
    {
        return new RefundIssued('tenant-1', 'intent-1', 'order-1', 'refund-1', Money::of($amount, Currency::USD), Money::of($previously, Currency::USD), Settlement::Order, CarbonImmutable::now());
    }

    #[Test]
    public function a_refund_returns_its_proportion_of_the_tax_rounded_down_cumulatively(): void
    {
        // 100.00 + 20% tax = 120.00
        $order = $this->order(10000, 2000);

        self::assertSame(833, PaymentPostings::taxShare($this->refund(5000, 0), $order)->amount);
        self::assertSame(1167, PaymentPostings::taxShare($this->refund(7000, 5000), $order)->amount);
        self::assertSame(2000, PaymentPostings::taxShare($this->refund(12000, 0), $order)->amount);
        self::assertSame(0, PaymentPostings::taxShare($this->refund(5000, 0), $this->order(10000, 0))->amount);
    }

    /**
     * Whatever the split, the refunds of a payment return exactly its tax.
     */
    #[Test]
    public function any_sequence_of_partial_refunds_returns_exactly_the_tax(): void
    {
        mt_srand(20261125);

        for ($run = 0; $run < 500; $run++) {
            $subtotal = mt_rand(1, 500_000);
            $tax = mt_rand(0, intdiv($subtotal, 2));
            $order = $this->order($subtotal, $tax);
            $left = $subtotal + $tax;
            $refunded = 0;
            $taxReturned = 0;

            while ($left > 0) {
                $amount = $left === 1 ? 1 : mt_rand(1, $left);
                $share = PaymentPostings::taxShare($this->refund($amount, $refunded), $order)->amount;

                self::assertGreaterThanOrEqual(0, $share);
                self::assertLessThanOrEqual($amount, $share);

                $taxReturned += $share;
                $refunded += $amount;
                $left -= $amount;
            }

            self::assertSame($tax, $taxReturned, sprintf('run %d: subtotal %d, tax %d', $run, $subtotal, $tax));
        }
    }
}
