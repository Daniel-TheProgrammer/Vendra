<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Gateway\GatewayRejected;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Queries\FindPaymentIntent;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Application\Services\PaymentSettlement;
use App\Modules\Payments\Domain\IllegalPaymentTransition;
use App\Modules\Payments\Domain\PaymentDeclined;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Domain\Exception\VersionMismatch;

/**
 * Takes the money the customer authorised. On success the order is paid
 * and PaymentCaptured is raised; on a decline the intent fails and the
 * caller gets 402.
 *
 * The provider is the authority on whether the intent is authorised (our
 * copy may not have heard yet), so any open intent may be captured and the
 * provider's refusal comes back as 409.
 */
final readonly class CapturePaymentIntent
{
    public function __construct(
        private FindPaymentIntent $find,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private PaymentSettlement $settlement,
    ) {}

    /**
     * @throws EntityNotFound
     * @throws VersionMismatch
     * @throws IllegalPaymentTransition
     * @throws PaymentDeclined
     * @throws GatewayRejected
     * @throws PaymentGatewayUnavailable
     */
    public function __invoke(string $intentId, ?int $expectedVersion = null): PaymentIntent
    {
        $intent = ($this->find)($intentId);

        if ($expectedVersion !== null && $expectedVersion !== $intent->version) {
            throw VersionMismatch::of('payment intent', $expectedVersion, $intent->version);
        }

        if (! $intent->status->isOpen() || $intent->gateway_ref === null) {
            throw IllegalPaymentTransition::between($intent->status, PaymentIntentStatus::Succeeded);
        }

        $remote = $this->gateway->capture($this->accounts->current(), $intent->gateway_ref, 'capture:'.$intent->id);
        $intent = $this->settlement->apply($intent->id, $remote->toUpdate());

        if ($intent->status === PaymentIntentStatus::Failed) {
            throw PaymentDeclined::forIntent($intent->id, $intent->failure_code, $intent->failure_message);
        }

        return $intent;
    }
}
