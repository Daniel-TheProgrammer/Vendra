<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\PaymentSettlement;
use App\Modules\Payments\Application\Services\PaymentUpdate;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use Psr\Log\LoggerInterface;

/**
 * An order was cancelled: whatever payment is still in flight for it is
 * released at the provider, so the customer's hold drops off their card.
 *
 * If the provider says the money was already captured, that news is applied
 * instead, and the payment settles as customer credit.
 */
final readonly class CancelOpenPayments
{
    public function __construct(
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private PaymentSettlement $settlement,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(string $orderId): void
    {
        $open = PaymentIntent::query()
            ->where('order_id', $orderId)
            ->whereIn('status', PaymentIntentStatus::openValues())
            ->get();

        foreach ($open as $intent) {
            $this->settlement->apply($intent->id, $this->cancelRemotely($intent));
        }
    }

    private function cancelRemotely(PaymentIntent $intent): PaymentUpdate
    {
        if ($intent->gateway_ref === null) {
            return PaymentUpdate::cancelled();
        }

        $account = $this->accounts->current();

        try {
            return $this->gateway->cancel($account, $intent->gateway_ref, 'cancel:'.$intent->id)->toUpdate();
        } catch (GatewayRejected) {
            // Already captured or failed: take the provider's word for it.
            return $this->gateway->retrieve($account, $intent->gateway_ref)->toUpdate();
        } catch (PaymentGatewayUnavailable $unavailable) {
            // The provider's own expiry (or a later webhook) settles it;
            // our copy stops accepting a capture now.
            $this->logger->warning('Could not cancel a payment at the provider; cancelled locally.', [
                'payment_intent_id' => $intent->id,
                'error' => $unavailable->getMessage(),
            ]);

            return PaymentUpdate::cancelled();
        }
    }
}
