<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Services;

use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Shared\Application\Clock\Clock;

/**
 * The one place an order changes status: the transition rules are applied,
 * the version moves and the history records it, all in the caller's
 * transaction. Side effects that belong to a transition (releasing or
 * shipping stock) stay in the action that asks for it.
 */
final readonly class OrderTransitions
{
    public function __construct(
        private OrderHistory $history,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $data  recorded with the history entry
     *
     * @throws IllegalTransition
     */
    public function apply(Order $order, OrderStatus $to, ?string $actorId = null, array $data = []): void
    {
        $from = $order->moveTo($to, $this->clock->now());
        $order->save();

        $this->history->record($order, $to->value, $from, $actorId, array_filter($data, static fn (mixed $value): bool => $value !== null));
    }
}
