<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Reconciliation;

/**
 * One place where the provider and Vendra disagree about money.
 */
final readonly class PaymentDiscrepancy
{
    /** The provider took money that no succeeded intent of ours accounts for. */
    public const UNSETTLED_CHARGE = 'unsettled_charge';

    /** We settled a payment the provider has no charge for. */
    public const MISSING_CHARGE = 'missing_charge';

    /** Both agree a charge happened, not on the fee. */
    public const FEE_MISMATCH = 'fee_mismatch';

    /** The provider's net balance movement differs from the ledger's Cash / Stripe Clearing. */
    public const CLEARING_MISMATCH = 'clearing_mismatch';

    public function __construct(
        public string $tenantId,
        public string $kind,
        public string $reference,
        public string $detail,
    ) {}

    /**
     * @return array{tenant_id: string, kind: string, reference: string, detail: string}
     */
    public function toArray(): array
    {
        return ['tenant_id' => $this->tenantId, 'kind' => $this->kind, 'reference' => $this->reference, 'detail' => $this->detail];
    }
}
