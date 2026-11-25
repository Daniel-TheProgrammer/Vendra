<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Orders\Application\Actions\RefundOrder;
use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Payments\Domain\Events\RefundIssued;
use App\Modules\Payments\Domain\RefundStatus;
use App\Modules\Payments\Domain\Settlement;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Modules\Payments\Infrastructure\Models\Refund;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Money\Money;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * The one place a refund changes status, from our own refund call or from
 * the provider's webhook. Repeated or impossible news changes nothing.
 *
 * Under the payment's row lock, so concurrent refunds of one payment see
 * each other: a succeeded refund raises RefundIssued with what earlier
 * refunds returned, and the refund that returns the last of an order's
 * payment moves the order to refunded.
 */
final readonly class RefundSettlement
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
        private RefundOrder $refundOrder,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws EntityNotFound
     */
    public function apply(string $refundId, RefundStatus $status, ?string $failureCode = null): Refund
    {
        $issued = null;

        $refund = $this->transaction->run(function () use ($refundId, $status, $failureCode, &$issued): Refund {
            $refund = Refund::query()->whereKey($refundId)->first() ?? throw EntityNotFound::withId('Refund', $refundId);
            // Payment first, then refund: the order every refund path locks in.
            $intent = PaymentIntent::query()->whereKey($refund->payment_intent_id)->lockForUpdate()->firstOrFail();
            $refund = Refund::query()->whereKey($refundId)->lockForUpdate()->firstOrFail();

            if (! $refund->status->canTransitionTo($status)) {
                return $refund;
            }

            $previously = Money::of((int) Refund::query()
                ->where('payment_intent_id', $intent->id)
                ->where('status', RefundStatus::Succeeded)
                ->sum('amount_minor'), $intent->currency);

            $now = $this->clock->now();
            $refund->moveTo($status, $now);
            $refund->failure_code = $status === RefundStatus::Failed ? ($failureCode ?? 'refund_failed') : null;
            $refund->save();

            if ($status === RefundStatus::Succeeded) {
                $settlement = $intent->settlement ?? Settlement::Order;
                $issued = new RefundIssued($intent->tenant_id, $intent->id, $intent->order_id, $refund->id, $refund->amount(), $previously, $settlement, $now);

                if ($settlement === Settlement::Order && $previously->add($refund->amount())->equals($intent->amount())) {
                    $this->refundOrder($intent, $refund);
                }
            }

            return $refund;
        });

        if ($issued instanceof RefundIssued) {
            $this->events->dispatch($issued);
        }

        return $refund;
    }

    private function refundOrder(PaymentIntent $intent, Refund $refund): void
    {
        try {
            ($this->refundOrder)($intent->order_id, $refund->id);
        } catch (IllegalTransition $refused) {
            $this->logger->warning('The order\'s payment was refunded in full but the order cannot become refunded.', [
                'order_id' => $intent->order_id,
                'refund_id' => $refund->id,
                'reason' => $refused->getMessage(),
            ]);
        }
    }
}
