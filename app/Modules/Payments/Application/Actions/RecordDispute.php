<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Domain\Events\PaymentDisputed;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Marks a payment as disputed and tells whoever listens (webhooks to the
 * merchant, Phase 7). Recorded once: a redelivered dispute changes nothing.
 *
 * The ledger does not move yet. A dispute is a claim, not a loss: the
 * provider books the withheld amount, and the outcome (won or lost) decides
 * what Vendra books. That arrives with dispute resolution, not here.
 */
final readonly class RecordDispute
{
    public function __construct(
        private TransactionRunner $transaction,
        private EventRecorder $events,
    ) {}

    /**
     * @return bool false when the payment was already marked disputed
     */
    public function __invoke(string $intentId, Money $amount, ?string $reason, CarbonImmutable $at): bool
    {
        return $this->transaction->run(function () use ($intentId, $amount, $reason, $at): bool {
            $intent = PaymentIntent::query()->whereKey($intentId)->lockForUpdate()->first();

            if (! $intent instanceof PaymentIntent || $intent->disputed_at !== null) {
                return false;
            }

            $intent->disputed_at = $at;
            $intent->save();
            $this->events->record(new PaymentDisputed($intent->tenant_id, $intent->id, $intent->order_id, $amount, $reason, $at));

            return true;
        });
    }
}
