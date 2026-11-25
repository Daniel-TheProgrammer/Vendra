<?php

declare(strict_types=1);

namespace App\Modules\Payments\Tests\Unit;

use App\Modules\Payments\Domain\IllegalPaymentTransition;
use App\Modules\Payments\Domain\PaymentIntentStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentIntentStatusTest extends TestCase
{
    /**
     * The diagram in PaymentIntentStatus, as data. Anything not listed is
     * refused.
     */
    private const ALLOWED = [
        'requires_payment' => ['processing', 'succeeded', 'failed', 'cancelled'],
        'processing' => ['succeeded', 'failed', 'cancelled'],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    /**
     * Every ordered pair of statuses: 25 cases.
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
        } catch (IllegalPaymentTransition $refused) {
            self::assertSame(409, $refused->status());
            self::assertSame('illegal-payment-transition', $refused->type());
            self::assertSame(['status' => $from->value, 'attempted' => $to->value, 'allowed' => self::ALLOWED[$from->value]], $refused->extensions());
        }
    }

    #[Test]
    public function only_unsettled_intents_are_open_and_only_settled_ones_are_final(): void
    {
        foreach (S::cases() as $status) {
            self::assertSame(in_array($status, [S::RequiresPayment, S::Processing], true), $status->isOpen(), $status->value);
            self::assertSame(! $status->isOpen(), $status->isFinal(), $status->value);
        }

        self::assertSame(['requires_payment', 'processing'], S::openValues());
    }

    #[Test]
    public function the_database_check_constraint_lists_every_status(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__, 2).'/Database/Migrations/2026_11_25_000300_create_payment_intents_tables.php');

        self::assertStringContainsString(
            "status IN ('".implode("', '", array_map(static fn (S $status): string => $status->value, S::cases()))."')",
            $migration,
        );
    }
}
