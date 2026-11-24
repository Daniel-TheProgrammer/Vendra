<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Ledger\Application\Actions\PostJournalEntry;
use App\Modules\Ledger\Application\Actions\ReverseJournalEntry;
use App\Modules\Ledger\Application\Commands\EntryMetadata;
use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\EntryAlreadyReversed;
use App\Modules\Ledger\Domain\EntryKind;
use App\Modules\Ledger\Domain\JournalEntry;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Modules\Tenancy\Application\Actions\CreateTenant;
use App\Modules\Tenancy\Application\Commands\CreateTenantCommand;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private LedgerAccount $receivable;

    private LedgerAccount $revenue;

    private LedgerAccount $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = $this->app->make(TenantContext::class);
        $this->context->set(Tenant::factory()->create()->id);

        $accounts = $this->app->make(Accounts::class);
        $this->receivable = $accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, Currency::USD);
        $this->revenue = $accounts->get(ChartOfAccounts::SALES_REVENUE, Currency::USD);
        $this->tax = $accounts->get(ChartOfAccounts::TAX_PAYABLE, Currency::USD);
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        parent::tearDown();
    }

    private function usd(int $minor): Money
    {
        return Money::of($minor, Currency::USD);
    }

    private function postSale(string $key, int $net = 1000, int $tax = 200, ?CarbonImmutable $at = null): JournalEntryRecord
    {
        $entry = JournalEntry::draft(Currency::USD, 'Sale '.$key)
            ->debit($this->receivable->id, $this->usd($net + $tax))
            ->credit($this->revenue->id, $this->usd($net));

        if ($tax > 0) {
            $entry = $entry->credit($this->tax->id, $this->usd($tax));
        }

        return $this->app->make(PostJournalEntry::class)($entry->post(), new EntryMetadata($key, referenceType: 'order', referenceId: $key, postedAt: $at));
    }

    private function balance(LedgerAccount $account, ?CarbonImmutable $asOf = null): int
    {
        return $this->app->make(FindAccountBalance::class)($account->id, $asOf)->balance()->amount;
    }

    #[Test]
    public function posting_writes_the_entry_and_moves_every_balance(): void
    {
        $entry = $this->postSale('order:1:placed');

        self::assertSame(EntryKind::System, $entry->kind);
        self::assertCount(3, $entry->lines()->get());
        self::assertSame(1200, $this->balance($this->receivable));
        self::assertSame(1000, $this->balance($this->revenue));
        self::assertSame(200, $this->balance($this->tax));
    }

    #[Test]
    public function the_same_key_posts_once(): void
    {
        $first = $this->postSale('order:1:placed');
        $second = $this->postSale('order:1:placed', 9999, 0);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, JournalEntryRecord::query()->count());
        self::assertSame(1200, $this->balance($this->receivable));
    }

    #[Test]
    public function a_balance_can_be_read_as_of_any_instant(): void
    {
        $this->postSale('a', 1000, 0, CarbonImmutable::parse('2026-11-01T09:00:00Z'));
        $this->postSale('b', 500, 0, CarbonImmutable::parse('2026-11-15T09:00:00Z'));

        self::assertSame(0, $this->balance($this->revenue, CarbonImmutable::parse('2026-10-31T23:59:59Z')));
        self::assertSame(1000, $this->balance($this->revenue, CarbonImmutable::parse('2026-11-01T09:00:00Z')));
        self::assertSame(1000, $this->balance($this->revenue, CarbonImmutable::parse('2026-11-15T10:59:59+02:00')));
        self::assertSame(1500, $this->balance($this->revenue, CarbonImmutable::parse('2026-11-15T09:00:00Z')));
        self::assertSame(1500, $this->balance($this->revenue));
    }

    #[Test]
    public function a_reversal_undoes_an_entry_once_and_can_itself_be_reversed(): void
    {
        $original = $this->postSale('order:2:placed');
        $reverse = $this->app->make(ReverseJournalEntry::class);

        $reversal = $reverse($original->id, 'order cancelled');
        self::assertSame(EntryKind::Reversal, $reversal->kind);
        self::assertSame($original->id, $reversal->reverses_entry_id);
        self::assertSame('order', $reversal->reference_type);
        self::assertSame(0, $this->balance($this->receivable));
        self::assertSame(0, $this->balance($this->revenue));

        try {
            $reverse($original->id, 'again');
            self::fail('An entry is reversed at most once.');
        } catch (EntryAlreadyReversed $exception) {
            self::assertSame(['reversed_by' => $reversal->id], $exception->extensions());
        }

        $reverse($reversal->id, 'cancellation was a mistake');
        self::assertSame(1200, $this->balance($this->receivable));
        self::assertSame(3, JournalEntryRecord::query()->count());
    }

    #[Test]
    public function accounts_open_once_per_currency_on_first_use(): void
    {
        $accounts = $this->app->make(Accounts::class);
        $eur = $accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, Currency::EUR);

        self::assertNotSame($this->receivable->id, $eur->id);
        self::assertSame($eur->id, $accounts->get(ChartOfAccounts::ACCOUNTS_RECEIVABLE, Currency::EUR)->id);
        self::assertSame('debit', $eur->normal_balance->value);
    }

    #[Test]
    public function a_new_tenant_gets_the_default_chart_of_accounts(): void
    {
        $this->context->clear();
        $tenant = $this->app->make(CreateTenant::class)(new CreateTenantCommand('Ledger Shop'));

        $codes = $this->context->runAs($tenant->id, static fn (): array => LedgerAccount::query()->where('currency', 'USD')->orderBy('code')->pluck('code')->all());

        self::assertSame(ChartOfAccounts::codes(), $codes);
    }

    #[Test]
    public function the_database_refuses_an_unbalanced_entry_even_when_the_application_is_bypassed(): void
    {
        $entryId = (string) Str::uuid7();
        $tenantId = $this->context->id();

        try {
            // A savepoint, so the failure does not poison the test's transaction.
            DB::transaction(function () use ($entryId, $tenantId): void {
                DB::table('journal_entries')->insert([
                    'id' => $entryId, 'tenant_id' => $tenantId, 'currency' => 'USD', 'description' => 'Raw insert',
                    'idempotency_key' => 'raw', 'kind' => 'system', 'posted_at' => now(), 'created_at' => now(),
                ]);
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'entry_id' => $entryId, 'position' => 1,
                    'account_id' => $this->receivable->id, 'currency' => 'USD', 'debit' => 500, 'credit' => 0,
                ]);

                // The balance check is deferred to commit; force it now.
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            self::fail('An unbalanced entry must not survive the balance check.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('does not balance: 500 debit vs 0 credit over 1 line(s)', $exception->getMessage());
        }

        self::assertSame(0, JournalEntryRecord::query()->count());
    }

    #[Test]
    public function the_database_refuses_malformed_lines_and_any_change_to_history(): void
    {
        $entry = $this->postSale('order:3:placed');
        $line = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->context->id(), 'entry_id' => $entry->id, 'position' => 9, 'account_id' => $this->revenue->id, 'currency' => 'USD'];

        $violations = [
            'journal_lines_one_side_check' => static fn () => DB::table('journal_lines')->insert([...$line, 'debit' => 5, 'credit' => 5]),
            // The line's currency must be its entry's and its account's.
            'currency_foreign' => static fn () => DB::table('journal_lines')->insert([...$line, 'currency' => 'EUR', 'debit' => 5, 'credit' => 0]),
            'append-only' => static fn () => DB::table('journal_lines')->where('entry_id', $entry->id)->update(['debit' => 1]),
            'journal_entries is append-only' => static fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        ];

        foreach ($violations as $expected => $statement) {
            try {
                DB::transaction($statement);
                self::fail($expected.' must be enforced');
            } catch (QueryException $exception) {
                self::assertStringContainsString($expected, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function reconciliation_proves_zero_drift_and_reports_any_tampering(): void
    {
        $this->postSale('order:4:placed');
        $this->context->clear();

        $this->runArtisan('ledger:reconcile')->expectsOutputToContain('zero drift')->assertSuccessful();

        // Someone writes around the ledger.
        $tenantId = Tenant::query()->orderBy('created_at')->value('id');
        $this->context->runAs((string) $tenantId, fn () => DB::table('account_balances')->where('account_id', $this->revenue->id)->update(['credit_total' => 1]));

        $log = Log::spy();
        $this->runArtisan('ledger:reconcile')->expectsOutputToContain('account 4000 USD')->assertFailed();
        $log->shouldHaveReceived('critical')->withArgs(static fn (string $message, array $context): bool => $message === 'Ledger balance drift' && $context['actualCredits'] === 1000)->once();
    }
}
