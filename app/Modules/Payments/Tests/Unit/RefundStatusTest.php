<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Unit;

use App\Modules\Payments\Domain\IllegalRefundTransition;
use App\Modules\Payments\Domain\RefundStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RefundStatusTest extends TestCase
{
    private const ALLOWED = [
        'pending' => ['succeeded', 'failed'],
        'succeeded' => [],
        'failed' => [],
    ];

    /**
     * Every ordered pair: 9 cases.
     *
     * @return iterable<string, array{S, S}>
     */
    public static function pairs(): iterable
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                yield $from->value.' -> '.$to->value => [$from, $to];
            }
        }
    }

    #[Test]
    #[DataProvider('pairs')]
    public function every_pair_is_allowed_or_refused_exactly_as_drawn(S $from, S $to): void
    {
        $allowed = in_array($to->value, self::ALLOWED[$from->value], true);

        self::assertSame($allowed, $from->canTransitionTo($to));

        if ($allowed) {
            self::assertSame($to, $from->transitionTo($to));

            return;
        }

        try {
            $from->transitionTo($to);
            self::fail(sprintf('%s -> %s must be refused', $from->value, $to->value));
        } catch (IllegalRefundTransition $refused) {
            self::assertSame(409, $refused->status());
            self::assertSame(['status' => $from->value, 'attempted' => $to->value, 'allowed' => self::ALLOWED[$from->value]], $refused->extensions());
        }
    }

    #[Test]
    public function only_failed_refunds_give_their_amount_back_to_what_may_be_refunded(): void
    {
        self::assertSame([true, true, false], array_map(static fn (S $status): bool => $status->holdsAmount(), S::cases()));
        self::assertSame([false, true, true], array_map(static fn (S $status): bool => $status->isFinal(), S::cases()));
    }
}
