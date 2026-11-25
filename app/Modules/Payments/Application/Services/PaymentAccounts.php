<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Application\Gateway\GatewayAccount;
use App\Modules\Payments\Application\Gateway\PaymentGateway;
use App\Modules\Payments\Domain\PaymentAccountMissing;
use App\Modules\Payments\Infrastructure\Models\PaymentAccount;
use App\Shared\Application\Clock\Clock;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Config\Repository as Config;
use LogicException;

/**
 * The current tenant's connection to the payment provider.
 *
 * The provider account id is encrypted at rest (it identifies where the
 * tenant's money goes). It is matched through an HMAC instead: the same id
 * always gives the same lookup value, which cannot be reversed without the
 * application key.
 */
final readonly class PaymentAccounts
{
    public function __construct(
        private TenantContext $tenant,
        private PaymentGateway $gateway,
        private Config $config,
        private Clock $clock,
    ) {}

    /**
     * @throws PaymentAccountMissing
     */
    public function current(): GatewayAccount
    {
        $account = $this->find() ?? throw PaymentAccountMissing::forTenant();

        return new GatewayAccount($account->account_id);
    }

    public function find(): ?PaymentAccount
    {
        return PaymentAccount::query()->where('tenant_id', $this->tenant->id())->first();
    }

    /**
     * Connects the tenant to an account, or switches it to another one.
     */
    public function connect(string $accountId): PaymentAccount
    {
        $account = $this->find() ?? new PaymentAccount(['tenant_id' => $this->tenant->id()]);
        $account->fill([
            'gateway' => $this->gateway->name(),
            'account_id' => $accountId,
            'account_lookup' => $this->lookup($accountId),
        ])->save();

        return $account;
    }

    /**
     * Sets the signing secret of the tenant's webhook endpoint, keeping the
     * previous one valid for $overlapHours (docs/payments.md, "Rotating the
     * webhook secret").
     *
     * @throws PaymentAccountMissing
     */
    public function rotateWebhookSecret(string $secret, int $overlapHours): PaymentAccount
    {
        $account = $this->find() ?? throw PaymentAccountMissing::forTenant();
        $account->rotateWebhookSecret($secret, $this->clock->now(), $overlapHours);
        $account->save();

        return $account;
    }

    /**
     * The account an inbound webhook names, in whichever tenant owns it.
     * The only read of this table that crosses tenants.
     */
    public function findByProviderAccount(string $accountId): ?PaymentAccount
    {
        return PaymentAccount::query()->where('account_lookup', $this->lookup($accountId))->first();
    }

    public function lookup(string $accountId): string
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw new LogicException('The application key must be set to match payment accounts.');
        }

        return hash_hmac('sha256', $accountId, $key);
    }
}
