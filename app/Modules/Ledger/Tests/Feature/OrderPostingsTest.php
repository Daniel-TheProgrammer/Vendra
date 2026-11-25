<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Ledger\Application\Queries\FindAccountBalance;
use App\Modules\Ledger\Application\Services\Accounts;
use App\Modules\Ledger\Domain\ChartOfAccounts;
use App\Modules\Ledger\Domain\EntryKind;
use App\Modules\Ledger\Infrastructure\Models\JournalEntryRecord;
use App\Modules\Orders\Application\Actions\RecordPayment;
use App\Modules\Orders\Application\Actions\RefundOrder;
use App\Modules\Orders\Application\Actions\RequestPayment;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Orders\Domain\Pricing\TaxStrategy;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * The order lifecycle, booked in the ledger through the real event flow.
 * Prices: 25.00 per unit, 20% tax.
 */
#[Group('database')]
final class OrderPostingsTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['orders.tax_rate_basis_points' => 2000]);
        $this->app->forgetInstance(TaxStrategy::class);

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->variant = $this->asTenant(function (): string {
            $variant = ProductVariant::query()->where('product_id', Product::factory()->withVariant('BOOK-1', 2500)->create()->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 20, AdjustmentReason::Received));

            return $variant;
        });

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
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

    private function place(int $quantity): string
    {
        return $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'reader@example.com'],
            'lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
    }

    /**
     * Account codes are digit strings, which PHP would turn into integer
     * keys; the balances are keyed by name instead.
     *
     * @return array{cash: int, receivable: int, tax: int, revenue: int}
     */
    private function balances(): array
    {
        return $this->asTenant(function (): array {
            $accounts = $this->app->make(Accounts::class);
            $find = $this->app->make(FindAccountBalance::class);
            $of = static fn (string $code): int => $find($accounts->get($code, Currency::USD)->id)->balance()->amount;

            return [
                'cash' => $of(ChartOfAccounts::CASH_CLEARING),
                'receivable' => $of(ChartOfAccounts::ACCOUNTS_RECEIVABLE),
                'tax' => $of(ChartOfAccounts::TAX_PAYABLE),
                'revenue' => $of(ChartOfAccounts::SALES_REVENUE),
            ];
        });
    }

    /**
     * Debits minus credits over every line of the tenant: zero for any ledger.
     */
    private function trialBalance(): int
    {
        return (int) $this->asTenant(static fn (): mixed => DB::table('journal_lines')->sum(DB::raw('debit - credit')));
    }

    #[Test]
    public function placing_an_order_books_the_receivable_revenue_and_tax(): void
    {
        $id = $this->place(2);

        self::assertSame(['cash' => 0, 'receivable' => 6000, 'tax' => 1000, 'revenue' => 5000], $this->balances());

        $entry = $this->asTenant(static fn (): JournalEntryRecord => JournalEntryRecord::query()->where('idempotency_key', 'order:'.$id.':placed')->sole());
        self::assertSame('order', $entry->reference_type);
        self::assertSame($id, $entry->reference_id);
        self::assertSame('Order 1001 placed', $entry->description);
        self::assertSame(0, $this->trialBalance());
    }

    #[Test]
    public function the_same_event_delivered_twice_books_once(): void
    {
        $id = $this->place(1);

        event(new OrderPlaced($this->tenant->id, $id, 1, CarbonImmutable::now()));

        self::assertSame(1, $this->asTenant(static fn (): int => JournalEntryRecord::query()->count()));
        self::assertSame(3000, $this->balances()['receivable']);
    }

    #[Test]
    public function cancelling_reverses_the_sale(): void
    {
        $id = $this->place(1);
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk();

        self::assertSame(['cash' => 0, 'receivable' => 0, 'tax' => 0, 'revenue' => 0], $this->balances());

        $kinds = $this->asTenant(static fn (): array => JournalEntryRecord::query()->orderBy('id')->pluck('kind')->all());
        self::assertSame([EntryKind::System, EntryKind::Reversal], $kinds);
    }

    /**
     * The money is booked from the Payments events (PaymentPostings), which
     * know the provider's fee and what was actually refunded. The order's
     * own status changes must not book it a second time.
     */
    #[Test]
    public function the_order_becoming_paid_or_refunded_books_nothing_by_itself(): void
    {
        $id = $this->place(4);

        $this->asTenant(function () use ($id): void {
            $this->app->make(RequestPayment::class)($id);
            $this->app->make(RecordPayment::class)($id, 'pi_1');
            $this->app->make(RefundOrder::class)($id, 're_1');
        });

        self::assertSame(['cash' => 0, 'receivable' => 12000, 'tax' => 2000, 'revenue' => 10000], $this->balances());
        self::assertSame(1, $this->asTenant(static fn (): int => JournalEntryRecord::query()->count()));
        self::assertSame(0, $this->trialBalance());
    }
}
