<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Infrastructure\Models\LedgerAccount;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class LedgerApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private LedgerAccount $cash;

    private LedgerAccount $equity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        [$this->cash, $this->equity] = $this->asTenant(function (): array {
            $accounts = $this->app->make(Accounts::class);
            $accounts->seed(Currency::USD);

            return [$accounts->get(ChartOfAccounts::CASH_CLEARING, Currency::USD), $accounts->get(ChartOfAccounts::OWNER_EQUITY, Currency::USD)];
        });

        $this->actingAsMember($this->tenant, MembershipRole::Owner);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function asTenant(callable $operation): mixed
    {
        return $this->context->runAs($this->tenant->id, $operation);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function postEntry(string $amount = '200.00', array $overrides = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/ledger/entries', [
            'currency' => 'USD',
            'description' => 'Opening cash float',
            'reason' => 'Till opened with the owner\'s cash',
            'lines' => [
                ['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => $amount],
                ['account_id' => $this->equity->id, 'side' => 'credit', 'amount' => $amount, 'memo' => 'Owner contribution'],
            ],
            ...$overrides,
        ], $headers);
    }

    #[Test]
    public function an_owner_posts_a_manual_entry_and_it_moves_the_balances(): void
    {
        $response = $this->postEntry(headers: ['Idempotency-Key' => 'float-1'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'manual')
            ->assertJsonPath('data.reason', 'Till opened with the owner\'s cash')
            ->assertJsonPath('data.lines.0.account.code', '1010')
            ->assertJsonPath('data.lines.0.side', 'debit')
            ->assertJsonPath('data.lines.1.amount', '200.00')
            ->assertJsonPath('data.lines.1.memo', 'Owner contribution');

        $response->assertHeader('Location', route('api.v1.ledger.entries.show', ['entry' => $response->json('data.id')]));

        // A retried request replays; it does not post a second entry.
        $this->postEntry(headers: ['Idempotency-Key' => 'float-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->getJson('/api/v1/ledger/accounts/'.$this->cash->id.'/balance')
            ->assertOk()
            ->assertJsonPath('data.balance', '200.00')
            ->assertJsonPath('data.balance_minor', 20000);

        // In code order: 1010, 1200, 2100, 2200, 3000, …
        $this->getJson('/api/v1/ledger/accounts')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.4.code', '3000')
            ->assertJsonPath('data.4.balance.balance', '200.00');
    }

    #[Test]
    public function entries_that_break_double_entry_are_refused(): void
    {
        $this->postEntry(overrides: ['lines' => [
            ['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => '200.00'],
            ['account_id' => $this->equity->id, 'side' => 'credit', 'amount' => '199.99'],
        ]])->assertUnprocessable()->assertJsonPath('type', 'urn:problem-type:journal-entry-invalid')->assertJsonPath('problem', 'unbalanced');

        $eurCash = $this->asTenant(fn (): string => $this->app->make(Accounts::class)->get(ChartOfAccounts::CASH_CLEARING, Currency::EUR)->id);
        $this->postEntry(overrides: ['lines' => [
            ['account_id' => $eurCash, 'side' => 'debit', 'amount' => '5'],
            ['account_id' => $this->equity->id, 'side' => 'credit', 'amount' => '5'],
        ]])->assertUnprocessable()->assertJsonPath('problem', 'mixed-currencies');

        $this->postEntry('1.234')->assertUnprocessable()->assertJsonStructure(['errors' => ['lines.0.amount']]);
        self::assertSame(['The amount must be greater than zero.'], $this->postEntry('0')->assertUnprocessable()->json('errors')['lines.0.amount'] ?? null);
        $this->postEntry(overrides: ['reason' => ''])->assertUnprocessable()->assertJsonStructure(['errors' => ['reason']]);
        $this->postEntry(overrides: ['lines' => [['account_id' => $this->cash->id, 'side' => 'debit', 'amount' => '1']]])->assertUnprocessable();

        $this->getJson('/api/v1/ledger/entries')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function only_owners_post_while_every_role_and_read_keys_can_read(): void
    {
        $this->postEntry()->assertCreated();

        foreach ([MembershipRole::Admin, MembershipRole::Staff, MembershipRole::ReadOnly] as $role) {
            $this->actingAsMember($this->tenant, $role);
            $this->getJson('/api/v1/ledger/entries')->assertOk();
            $this->postEntry()->assertForbidden();
        }

        $this->actingAsApiKey($this->tenant, [ApiScope::LedgerRead]);
        $this->getJson('/api/v1/ledger/trial-balance')->assertOk();
        $this->postEntry()->assertForbidden();
    }

    #[Test]
    public function an_entry_is_reversed_once_through_the_api(): void
    {
        $id = $this->postEntry()->json('data.id');

        $reversal = $this->postJson('/api/v1/ledger/entries/'.$id.'/reverse', ['reason' => 'Entered twice'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'reversal')
            ->assertJsonPath('data.reverses_entry_id', $id)
            ->assertJsonPath('data.lines.0.side', 'credit');

        $this->getJson('/api/v1/ledger/entries/'.$id)->assertJsonPath('data.reversed_by_entry_id', $reversal->json('data.id'));
        $this->postJson('/api/v1/ledger/entries/'.$id.'/reverse', ['reason' => 'again'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:entry-already-reversed');
        $this->postJson('/api/v1/ledger/entries/'.$id.'/reverse', [])->assertUnprocessable();

        $this->getJson('/api/v1/ledger/accounts/'.$this->cash->id.'/balance')->assertJsonPath('data.balance_minor', 0);
    }

    #[Test]
    public function the_trial_balance_balances_now_and_as_of_any_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T10:00:00Z'));
        $this->postEntry('100.00')->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        $this->postEntry('50.00')->assertCreated();

        $now = $this->getJson('/api/v1/ledger/trial-balance')->assertOk()->json('data.currencies.0');
        self::assertSame(['USD', '150.00', '150.00', true], [$now['currency'], $now['total_debits'], $now['total_credits'], $now['balanced']]);

        $this->getJson('/api/v1/ledger/trial-balance?as_of=2026-11-10T00:00:00Z')
            ->assertJsonPath('data.currencies.0.total_debits', '100.00')
            ->assertJsonPath('data.currencies.0.balanced', true);

        $this->getJson('/api/v1/ledger/accounts/'.$this->cash->id.'/balance?as_of=2026-11-10T00:00:00Z')->assertJsonPath('data.balance', '100.00');
        $this->getJson('/api/v1/ledger/trial-balance?as_of=yesterday')->assertUnprocessable();
    }

    #[Test]
    public function entries_are_filtered_and_belong_to_their_tenant(): void
    {
        $id = $this->postEntry()->json('data.id');

        self::assertSame([$id], array_column($this->getJson('/api/v1/ledger/entries?reference_type=manual')->json('data'), 'id'));
        self::assertSame([], $this->getJson('/api/v1/ledger/entries?reference_type=order')->json('data'));

        $this->actingAsMember(Tenant::factory()->create());
        $this->getJson('/api/v1/ledger/entries/'.$id)->assertNotFound();
        $this->getJson('/api/v1/ledger/accounts/'.$this->cash->id.'/balance')->assertNotFound();
        $this->postJson('/api/v1/ledger/entries/'.$id.'/reverse', ['reason' => 'x'])->assertNotFound();
    }

    #[Test]
    public function the_csv_export_streams_more_than_ten_thousand_rows_in_flat_memory(): void
    {
        $entries = 5_100;
        $this->asTenant(function () use ($entries): void {
            $now = CarbonImmutable::now();

            // Straight into the tables: fast, and the deferred balance check
            // never runs inside the test transaction.
            foreach (array_chunk(range(1, $entries), 500) as $chunk) {
                $rows = [];
                $lines = [];

                foreach ($chunk as $n) {
                    $id = (string) Str::uuid7();
                    $rows[] = ['id' => $id, 'tenant_id' => $this->tenant->id, 'currency' => 'USD', 'description' => 'Bulk '.$n, 'idempotency_key' => 'bulk:'.$n, 'kind' => 'system', 'posted_at' => $now, 'created_at' => $now];
                    $lines[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'entry_id' => $id, 'position' => 1, 'account_id' => $this->cash->id, 'currency' => 'USD', 'debit' => $n, 'credit' => 0];
                    $lines[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'entry_id' => $id, 'position' => 2, 'account_id' => $this->equity->id, 'currency' => 'USD', 'debit' => 0, 'credit' => $n];
                }

                DB::table('journal_entries')->insert($rows);
                DB::table('journal_lines')->insert($lines);
            }
        });

        memory_reset_peak_usage();
        $before = memory_get_usage();

        $response = $this->get('/api/v1/ledger/entries/export?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = (string) $response->streamedContent();

        $peak = memory_get_peak_usage() - $before;
        $rows = explode("\n", rtrim($csv, "\n"));

        self::assertCount($entries * 2 + 1, $rows, 'header plus one row per line');
        self::assertSame('entry_id,posted_at,kind,reference_type,reference_id,description,reason,line,account_code,account_name,currency,debit,credit,memo', $rows[0]);
        // Chronological: the first entry posted comes first.
        self::assertStringContainsString('"Bulk 1",,1,1010,"Cash / Stripe Clearing",USD,0.01,,', $rows[1]);
        self::assertStringContainsString('"Bulk 5100",,2,3000,"Owner Equity",USD,,51.00,', $rows[$entries * 2]);
        // The CSV itself is ~2 MB; everything else must stay flat.
        self::assertLessThan(24 * 1024 * 1024, $peak, sprintf('export peaked at %.1f MB', $peak / 1024 / 1024));
    }
}
