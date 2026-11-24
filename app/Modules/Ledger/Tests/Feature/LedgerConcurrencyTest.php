<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Application\Actions\PostJournalEntry;
use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Reconciliation\ReconcileBalances;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RacesWorkers;
use Tests\TestCase;

/**
 * 100 postings from 8 processes into the same accounts, committed for real:
 * the balance-row locks must serialise them without losing an update, the
 * deferred balance trigger runs on every commit, and one key posted by every
 * worker at once still books exactly once.
 */
#[Group('database')]
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class LedgerConcurrencyTest extends TestCase
{
    use RacesWorkers;

    private const WORKERS = 8;

    private const POSTINGS = 100;

    private TenantContext $context;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareRace();
        $this->context = $this->app->make(TenantContext::class);
        $this->tenantId = Tenant::factory()->create()->id;
    }

    protected function tearDown(): void
    {
        $this->context->clear();
        $this->endRace();

        parent::tearDown();
    }

    #[Test]
    public function concurrent_postings_to_the_same_accounts_add_up_exactly(): void
    {
        [$receivable, $revenue, $tax] = $this->context->runAs($this->tenantId, function (): array {
            $accounts = $this->app->make(Accounts::class);

            return [
                $accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, Currency::USD)->id,
                $accounts->get(ChartOfAccounts::SALES_REVENUE, Currency::USD)->id,
                $accounts->get(ChartOfAccounts::TAX_PAYABLE, Currency::USD)->id,
            ];
        });

        $results = $this->race(self::WORKERS, fn (int $worker): string => $this->context->runAs($this->tenantId, function () use ($worker, $receivable, $revenue, $tax): string {
            $post = $this->app->make(PostJournalEntry::class);
            $usd = static fn (int $minor): Money => Money::of($minor, Currency::USD);
            $posted = 0;

            // Postings n where n % WORKERS === worker: 100 between the 8.
            for ($n = $worker; $n < self::POSTINGS; $n += self::WORKERS) {
                $net = 100 + $n;
                $post(
                    JournalEntry::draft(Currency::USD, 'Posting '.$n)
                        ->debit($receivable, $usd($net + 10))
                        ->credit($revenue, $usd($net))
                        ->credit($tax, $usd(10))
                        ->post(),
                    new EntryMetadata('posting:'.$n),
                );
                $posted++;
            }

            $post(
                JournalEntry::draft(Currency::USD, 'Shared')->debit($receivable, $usd(1))->credit($revenue, $usd(1))->post(),
                new EntryMetadata('shared-by-every-worker'),
            );

            return 'posted '.$posted;
        }));

        self::assertSame(['posted 12' => 4, 'posted 13' => 4], $this->tally($results), implode("\n", $results));

        $this->context->runAs($this->tenantId, function () use ($receivable, $revenue, $tax): void {
            // Σ(100 + n) for n = 0..99, plus the one shared posting.
            $netTotal = 100 * 100 + 4950;
            $balance = $this->app->make(FindAccountBalance::class);

            self::assertSame($netTotal + 1000 + 1, $balance($receivable)->balance()->amount);
            self::assertSame($netTotal + 1, $balance($revenue)->balance()->amount);
            self::assertSame(1000, $balance($tax)->balance()->amount);
            self::assertSame(self::POSTINGS + 1, JournalEntryRecord::query()->count());
            self::assertSame([], $this->app->make(ReconcileBalances::class)());
        });
    }
}
