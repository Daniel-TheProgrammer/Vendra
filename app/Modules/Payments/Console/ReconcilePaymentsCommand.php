<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Application\Reconciliation\PaymentDiscrepancy;
use App\Modules\Payments\Application\Reconciliation\ReconcilePayments;
use App\Modules\Payments\Domain\PaymentGatewayUnavailable;
use App\Modules\Tenancy\Application\Queries\AllTenantIds;
use App\Shared\Application\Clock\Clock;
use App\Shared\Infrastructure\Http\Rfc3339;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily check that the provider's balance and the ledger agree (docs/payments.md).
 * Every discrepancy is logged at critical level and fails the command.
 */
final class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile
        {--since= : RFC 3339 instant or Y-m-d date to start from (default: 7 days ago)}';

    protected $description = 'Compare the payment provider\'s balance transactions with the ledger';

    public function handle(AllTenantIds $tenants, TenantContext $context, ReconcilePayments $reconcile, Clock $clock, LoggerInterface $log): int
    {
        $since = $this->since($clock);

        if (! $since instanceof CarbonImmutable) {
            $this->components->error('--since must be an RFC 3339 instant or a Y-m-d date.');

            return self::INVALID;
        }

        $found = 0;
        $unreachable = 0;

        foreach ($tenants() as $tenantId) {
            try {
                $discrepancies = $context->runAs($tenantId, static fn (): array => $reconcile($since));
            } catch (PaymentGatewayUnavailable $unavailable) {
                $unreachable++;
                $log->error('Payments not reconciled: the provider was unavailable.', ['tenant_id' => $tenantId, 'error' => $unavailable->getMessage()]);
                $this->components->warn(sprintf('Tenant %s: provider unavailable, not reconciled.', $tenantId));

                continue;
            }

            foreach ($discrepancies as $discrepancy) {
                /** @var PaymentDiscrepancy $discrepancy */
                $found++;
                $log->critical('Payment discrepancy', $discrepancy->toArray());
                $this->components->error(sprintf('Tenant %s, %s %s: %s', $discrepancy->tenantId, $discrepancy->kind, $discrepancy->reference, $discrepancy->detail));
            }
        }

        if ($found > 0 || $unreachable > 0) {
            return self::FAILURE;
        }

        $this->components->info(sprintf('Payments since %s reconciled; no discrepancies.', $since->toIso8601String()));

        return self::SUCCESS;
    }

    private function since(Clock $clock): ?CarbonImmutable
    {
        $option = $this->option('since');

        if (! is_string($option) || $option === '') {
            return $clock->now()->subDays(7);
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $option) === 1) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $option, 'UTC') ?: null;
            }

            return preg_match(Rfc3339::PATTERN, $option) === 1 ? Rfc3339::parse($option) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
