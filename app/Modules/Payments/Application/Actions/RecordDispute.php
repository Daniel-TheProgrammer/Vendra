<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Domain\Events\PaymentDisputed;
use App\Modules\Payments\Infrastructure\Models\PaymentIntent;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Domain\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;

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
        private Dispatcher $events,
    ) {}

    /**
     * @return bool false when the payment was already marked disputed
     */
    public function __invoke(string $intentId, Money $amount, ?string $reason, CarbonImmutable $at): bool
    {
        $intent = $this->transaction->run(static function () use ($intentId, $at): ?PaymentIntent {
            $intent = PaymentIntent::query()->whereKey($intentId)->lockForUpdate()->first();

            if (! $intent instanceof PaymentIntent || $intent->disputed_at !== null) {
                return null;
            }

            $intent->disputed_at = $at;
            $intent->save();

            return $intent;
        });

        if (! $intent instanceof PaymentIntent) {
            return false;
        }

        $this->events->dispatch(new PaymentDisputed($intent->tenant_id, $intent->id, $intent->order_id, $amount, $reason, $at));

        return true;
    }
}
