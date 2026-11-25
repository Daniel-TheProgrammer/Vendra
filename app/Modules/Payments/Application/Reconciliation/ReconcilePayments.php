<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Reconciliation;

use App\Modules\Ledger\Application\Postings\PaymentPostings;
use App\Modules\Ledger\Application\Queries\AccountMovement;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\GatewayBalanceTransaction;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Application\Services\PaymentAccounts;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Payments\Domain\PaymentIntentStatus;
use App\Modules\Payments\Infrastructure\Models\PaymentAccount;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Compares the provider's record of the current tenant's money with ours,
 * for movements since an instant:
 *
 *   - every charge at the provider has a succeeded intent here, with the
 *     same fee;
 *   - every intent we settled since then has a charge at the provider;
 *   - per currency, the provider's net movement (charges and refunds,
 *     after fees) equals what the ledger booked on Cash / Stripe Clearing
 *     from payments.
 *
 * Read-only. A discrepancy is for a person to investigate: the fix might be
 * a missed webhook to replay, or a manual ledger entry with a reason.
 */
final readonly class ReconcilePayments
{
    public function __construct(
        private TenantContext $tenant,
        private PaymentAccounts $accounts,
        private PaymentGateway $gateway,
        private AccountMovement $movement,
    ) {}

    /**
     * @return list<PaymentDiscrepancy>
     *
     * @throws PaymentGatewayUnavailable
     */
    public function __invoke(CarbonImmutable $since): array
    {
        $account = $this->accounts->find();

        if (! $account instanceof PaymentAccount || $account->gateway !== $this->gateway->name()) {
            return [];
        }

        $tenantId = $this->tenant->id();
        $discrepancies = [];
        /** @var array<string, Money> $providerNet keyed by currency code */
        $providerNet = [];
        $charged = [];

        foreach ($this->gateway->balanceTransactions(new GatewayAccount($account->account_id), $since) as $movement) {
            if (! in_array($movement->type, [GatewayBalanceTransaction::CHARGE, GatewayBalanceTransaction::REFUND], true)) {
                continue;
            }

            $code = $movement->net->currency->value;
            $providerNet[$code] = isset($providerNet[$code]) ? $providerNet[$code]->add($movement->net) : $movement->net;

            if ($movement->type === GatewayBalanceTransaction::CHARGE) {
                $charged[] = (string) $movement->paymentRef;
                $discrepancies = [...$discrepancies, ...$this->checkCharge($tenantId, $movement)];
            }
        }

        $settled = PaymentIntent::query()
            ->where('status', PaymentIntentStatus::Succeeded)
            ->where('gateway', $this->gateway->name())
            ->where('captured_at', '>=', $since->utc()->format('Y-m-d H:i:s.uP'))
            ->whereNotIn('gateway_ref', $charged)
            ->get(['id', 'gateway_ref']);

        foreach ($settled as $intent) {
            $discrepancies[] = new PaymentDiscrepancy($tenantId, PaymentDiscrepancy::MISSING_CHARGE, $intent->id, sprintf('Settled here, but the provider has no charge for %s.', $intent->gateway_ref));
        }

        foreach ($this->currencies($providerNet) as $currency) {
            $provider = $providerNet[$currency->value] ?? Money::zero($currency);
            $ledger = ($this->movement)(ChartOfAccounts::CASH_CLEARING, $currency, $since, [PaymentPostings::REFERENCE_TYPE]);

            if (! $provider->equals($ledger)) {
                $discrepancies[] = new PaymentDiscrepancy($tenantId, PaymentDiscrepancy::CLEARING_MISMATCH, $currency->value, sprintf('Provider net %s, ledger Cash / Stripe Clearing %s.', $provider->toDecimal(), $ledger->toDecimal()));
            }
        }

        return $discrepancies;
    }

    /**
     * @return list<PaymentDiscrepancy>
     */
    private function checkCharge(string $tenantId, GatewayBalanceTransaction $charge): array
    {
        $intent = $charge->paymentRef !== null ? PaymentIntent::query()->where('gateway_ref', $charge->paymentRef)->first() : null;

        if (! $intent instanceof PaymentIntent || $intent->status !== PaymentIntentStatus::Succeeded) {
            return [new PaymentDiscrepancy($tenantId, PaymentDiscrepancy::UNSETTLED_CHARGE, $charge->paymentRef ?? $charge->id, sprintf(
                'The provider charged %s %s; Vendra has %s.',
                $charge->amount->toDecimal(),
                $charge->amount->currency->value,
                $intent instanceof PaymentIntent ? 'the intent '.$intent->status->value : 'no intent',
            ))];
        }

        if ($intent->fee_minor !== $charge->fee->amount) {
            return [new PaymentDiscrepancy($tenantId, PaymentDiscrepancy::FEE_MISMATCH, $intent->id, sprintf('Provider fee %s, recorded %s.', $charge->fee->toDecimal(), Money::of((int) $intent->fee_minor, $intent->currency)->toDecimal()))];
        }

        return [];
    }

    /**
     * Currencies the provider moved, plus those the ledger booked payments
     * in, so money booked here but absent at the provider is caught too.
     *
     * @param  array<string, Money>  $providerNet
     * @return list<Currency>
     */
    private function currencies(array $providerNet): array
    {
        $codes = array_keys($providerNet);

        foreach (PaymentIntent::query()->where('status', PaymentIntentStatus::Succeeded)->distinct()->pluck('currency') as $currency) {
            $codes[] = $currency instanceof Currency ? $currency->value : (string) $currency;
        }

        return array_map(Currency::from(...), array_values(array_unique($codes)));
    }
}
