<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Domain\InvalidJournalEntry;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JournalEntryTest extends TestCase
{
    private function usd(int $minor): Money
    {
        return Money::of($minor, Currency::USD);
    }

    private function sale(): JournalEntry
    {
        return JournalEntry::draft(Currency::USD, 'Order 1001 placed')
            ->debit('receivable', $this->usd(1200))
            ->credit('revenue', $this->usd(1000))
            ->credit('tax', $this->usd(200), 'VAT 20%');
    }

    #[Test]
    public function a_balanced_entry_posts_with_its_lines_in_order(): void
    {
        $posted = $this->sale()->post();

        self::assertSame(Currency::USD, $posted->currency);
        self::assertSame('Order 1001 placed', $posted->description);
        self::assertSame(1200, $posted->total->amount);
        self::assertSame(['receivable', 'revenue', 'tax'], array_map(static fn (JournalLine $line): string => $line->accountId, $posted->lines));
        self::assertSame('VAT 20%', $posted->lines[2]->memo);
    }

    #[Test]
    public function an_unbalanced_entry_cannot_post(): void
    {
        try {
            $this->sale()->credit('revenue', $this->usd(1))->post();
            self::fail('An unbalanced entry must not post.');
        } catch (InvalidJournalEntry $exception) {
            self::assertSame('unbalanced', $exception->problem());
            self::assertSame(['problem' => 'unbalanced', 'debits' => 1200, 'credits' => 1201], $exception->extensions());
            self::assertSame(422, $exception->status());
            self::assertSame('journal-entry-invalid', $exception->type());
            self::assertSame('Journal entry does not balance', $exception->title());
        }
    }

    #[Test]
    public function an_entry_mixing_currencies_cannot_post_even_if_the_numbers_balance(): void
    {
        $entry = JournalEntry::draft(Currency::USD, 'Mixed')
            ->debit('a', $this->usd(500))
            ->credit('b', Money::of(500, Currency::EUR));

        $this->expectExceptionObject(InvalidJournalEntry::mixedCurrencies('USD', 'EUR'));

        $entry->post();
    }

    /**
     * @return iterable<string, array{JournalEntry}>
     */
    public static function oneSided(): iterable
    {
        $usd = static fn (int $minor): Money => Money::of($minor, Currency::USD);

        yield 'no lines' => [JournalEntry::draft(Currency::USD, 'Empty')];
        yield 'debits only' => [JournalEntry::draft(Currency::USD, 'D')->debit('a', $usd(1))->debit('b', $usd(1))];
        yield 'credits only' => [JournalEntry::draft(Currency::USD, 'C')->credit('a', $usd(1))];
    }

    #[Test]
    #[DataProvider('oneSided')]
    public function an_entry_needs_both_a_debit_and_a_credit(JournalEntry $entry): void
    {
        try {
            $entry->post();
            self::fail('A one-sided entry must not post.');
        } catch (InvalidJournalEntry $exception) {
            self::assertSame('too-few-lines', $exception->problem());
        }
    }

    #[Test]
    public function drafts_are_immutable(): void
    {
        $draft = JournalEntry::draft(Currency::USD, 'Draft');
        $draft->debit('a', $this->usd(5));

        self::assertSame([], $draft->lines);
    }

    #[Test]
    public function an_entry_needs_a_description(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JournalEntry::draft(Currency::USD, '   ');
    }

    #[Test]
    public function a_line_amount_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JournalEntry::draft(Currency::USD, 'Zero')->debit('a', $this->usd(0));
    }

    #[Test]
    public function reversing_swaps_every_side_and_reversing_again_restores_the_original(): void
    {
        $original = $this->sale()->post();
        $reversal = $original->reverse('Order 1001 cancelled');

        self::assertSame('Order 1001 cancelled', $reversal->description);
        self::assertSame([Side::Credit, Side::Debit, Side::Debit], array_map(static fn (JournalLine $line): Side => $line->side, $reversal->lines));
        self::assertEquals($original->total, $reversal->total);

        $again = $reversal->reverse('Order 1001 reinstated');
        self::assertEquals($original->lines, $again->lines);
    }

    /**
     * @return iterable<string, array{int, list<int>}>
     */
    public static function awkwardSplits(): iterable
    {
        yield 'thirds of a cent amount' => [100, [1, 1, 1]];
        yield 'prime total' => [9973, [3, 5, 7, 11]];
        yield 'one minor unit over many accounts' => [1, [1, 1, 1, 1]];
        yield 'uneven weights' => [1000, [1, 2, 4]];
    }

    /**
     * @param  list<int>  $ratios
     */
    #[Test]
    #[DataProvider('awkwardSplits')]
    public function splitting_never_loses_or_invents_a_minor_unit(int $total, array $ratios): void
    {
        $accounts = [];

        foreach ($ratios as $index => $ratio) {
            $accounts['account-'.$index] = $ratio;
        }

        $posted = JournalEntry::draft(Currency::USD, 'Split')
            ->debit('source', $this->usd($total))
            ->split(Side::Credit, $this->usd($total), $accounts)
            ->post();

        $credited = 0;

        foreach ($posted->lines as $line) {
            $credited += $line->side === Side::Credit ? $line->amount->amount : 0;
        }

        self::assertSame($total, $credited);
    }

    #[Test]
    public function zero_parts_of_a_split_are_left_out(): void
    {
        $entry = JournalEntry::draft(Currency::USD, 'Tiny')->split(Side::Credit, $this->usd(1), ['a' => 1, 'b' => 1]);

        self::assertCount(1, $entry->lines);
    }

    /**
     * Property: however entries are made up, if each one balances, the
     * ledger as a whole always nets to zero. 500 random entries.
     */
    #[Test]
    public function random_balanced_entries_always_leave_the_trial_balance_at_zero(): void
    {
        mt_srand(20261124);
        $net = [];

        for ($i = 0; $i < 500; $i++) {
            $total = mt_rand(1, 10_000_000);
            $debitAccounts = [];
            $creditAccounts = [];

            for ($n = mt_rand(1, 4); $n > 0; $n--) {
                $debitAccounts['acc-'.mt_rand(1, 8)] = mt_rand(1, 9);
            }

            for ($n = mt_rand(1, 4); $n > 0; $n--) {
                $creditAccounts['acc-'.mt_rand(1, 8)] = mt_rand(1, 9);
            }

            $posted = JournalEntry::draft(Currency::USD, 'Random '.$i)
                ->split(Side::Debit, $this->usd($total), $debitAccounts)
                ->split(Side::Credit, $this->usd($total), $creditAccounts)
                ->post();

            foreach ($posted->lines as $line) {
                $net[$line->accountId] = ($net[$line->accountId] ?? 0) + ($line->side === Side::Debit ? 1 : -1) * $line->amount->amount;
            }
        }

        self::assertSame(0, array_sum($net));
    }
}
