<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Domain\AccountBalance;
use App\Modules\Ledger\Domain\AccountType;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\EntryAlreadyReversed;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\Side;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LedgerDomainTest extends TestCase
{
    #[Test]
    public function each_account_type_grows_on_its_normal_side(): void
    {
        $normal = [];

        foreach (AccountType::cases() as $type) {
            $normal[$type->value] = $type->normalBalance()->value;
        }

        self::assertSame(['asset' => 'debit', 'liability' => 'credit', 'equity' => 'credit', 'revenue' => 'credit', 'expense' => 'debit'], $normal);
        self::assertSame(Side::Credit, Side::Debit->opposite());
        self::assertSame(Side::Debit, Side::Credit->opposite());
    }

    #[Test]
    public function a_balance_reads_in_the_accounts_normal_direction_and_may_go_negative(): void
    {
        $usd = static fn (int $minor): Money => Money::of($minor, Currency::USD);

        self::assertSame(70, (new AccountBalance($usd(100), $usd(30), Side::Debit))->balance()->amount);
        self::assertSame(-70, (new AccountBalance($usd(100), $usd(30), Side::Credit))->balance()->amount);
        self::assertSame(-5, (new AccountBalance($usd(0), $usd(5), Side::Debit))->balance()->amount);
    }

    #[Test]
    public function the_default_chart_covers_the_accounts_postings_need(): void
    {
        self::assertSame(['1010', '1200', '2100', '2200', '3000', '4000', '5100', '5200'], ChartOfAccounts::codes());
        self::assertSame(AccountType::Asset, ChartOfAccounts::definition(ChartOfAccounts::ACCOUNTS_RECEIVABLE)['type'] ?? null);
        self::assertSame(AccountType::Revenue, ChartOfAccounts::definition(ChartOfAccounts::SALES_REVENUE)['type'] ?? null);
        self::assertSame('Customer Credits', ChartOfAccounts::definition(ChartOfAccounts::CUSTOMER_CREDITS)['name'] ?? null);
        self::assertNull(ChartOfAccounts::definition('9999'));
    }

    #[Test]
    public function a_reversed_line_keeps_everything_but_its_side(): void
    {
        $line = new JournalLine('acc', Side::Debit, Money::of(5, Currency::USD), 'memo');
        $reversed = $line->reversed();

        self::assertSame(Side::Credit, $reversed->side);
        self::assertSame('acc', $reversed->accountId);
        self::assertSame('memo', $reversed->memo);
        self::assertTrue($reversed->amount->equals($line->amount));
    }

    #[Test]
    public function a_second_reversal_names_the_first(): void
    {
        $exception = EntryAlreadyReversed::by('entry-1', 'reversal-1');

        self::assertSame(409, $exception->status());
        self::assertSame('entry-already-reversed', $exception->type());
        self::assertSame('Entry already reversed', $exception->title());
        self::assertSame(['reversed_by' => 'reversal-1'], $exception->extensions());
    }
}
