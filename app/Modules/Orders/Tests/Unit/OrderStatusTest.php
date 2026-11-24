<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tests\Unit;

use App\Modules\Orders\Domain\IllegalTransition;
use App\Modules\Orders\Domain\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole transition matrix, every (from, to) pair, so adding a status or
 * an arrow without updating this table fails loudly.
 */
final class OrderStatusTest extends TestCase
{
    /**
     * The state machine as drawn in docs/orders-state-machine.md.
     */
    private const LEGAL = [
        'pending' => ['awaiting_payment', 'cancelled'],
        'awaiting_payment' => ['paid', 'cancelled'],
        'paid' => ['fulfilled', 'refunded'],
        'fulfilled' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    /**
     * @return iterable<string, array{OrderStatus, OrderStatus, bool}>
     */
    public static function everyPair(): iterable
    {
        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $legal = in_array($to->value, self::LEGAL[$from->value], true);

                yield sprintf('%s → %s %s', $from->value, $to->value, $legal ? 'allowed' : 'refused') => [$from, $to, $legal];
            }
        }
    }

    #[Test]
    #[DataProvider('everyPair')]
    public function every_transition_is_either_allowed_or_refused_as_drawn(OrderStatus $from, OrderStatus $to, bool $legal): void
    {
        self::assertSame($legal, $from->canTransitionTo($to));

        if ($legal) {
            self::assertSame($to, $from->transitionTo($to));

            return;
        }

        try {
            $from->transitionTo($to);
            self::fail(sprintf('%s → %s must be refused.', $from->value, $to->value));
        } catch (IllegalTransition $exception) {
            self::assertSame(409, $exception->status());
            self::assertSame(['status' => $from->value, 'attempted' => $to->value, 'allowed' => self::LEGAL[$from->value]], $exception->extensions());
        }
    }

    #[Test]
    public function the_table_covers_every_status(): void
    {
        self::assertSame(array_keys(self::LEGAL), array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases()));
    }

    #[Test]
    public function stock_is_held_until_the_order_ships_or_ends(): void
    {
        $holding = array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->holdsStock()));

        self::assertSame([OrderStatus::Pending, OrderStatus::AwaitingPayment, OrderStatus::Paid], $holding);
    }

    #[Test]
    public function only_cancelled_and_refunded_are_final(): void
    {
        $final = array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isFinal()));

        self::assertSame([OrderStatus::Cancelled, OrderStatus::Refunded], $final);
    }
}
