<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Domain\StockLevel;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Orders\Application\Actions\RecordPayment;
use App\Modules\Orders\Application\Actions\RequestPayment;
use App\Modules\Orders\Domain\Events\OrderCancelled;
use App\Modules\Orders\Domain\Events\OrderFulfilled;
use App\Modules\Orders\Domain\Events\OrderPlaced;
use App\Modules\Orders\Domain\Pricing\TaxStrategy;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class OrderApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    /** 12.50 USD, 5 in stock. */
    private string $mug;

    /** 20.00 USD, 2 in stock. */
    private string $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);

        [$this->mug, $this->tee] = $this->asTenant(function (): array {
            $mug = Product::factory()->withVariant('MUG-1', 1250)->create(['name' => 'Enamel Mug']);
            $tee = Product::factory()->withVariant('TEE-1', 2000)->create(['name' => 'Organic Tee']);
            $ids = [$this->variantOf($mug), $this->variantOf($tee)];

            foreach (array_combine($ids, [5, 2]) as $variantId => $quantity) {
                $this->app->make(AdjustStock::class)(new AdjustStockCommand($variantId, $quantity, AdjustmentReason::Received));
            }

            return $ids;
        });

        $this->actingAsMember($this->tenant, MembershipRole::Staff);
    }

    private function variantOf(Product $product): string
    {
        return ProductVariant::query()->where('product_id', $product->id)->valueOrFail('id');
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

    private function stock(string $variantId): StockLevel
    {
        return $this->asTenant(fn (): StockLevel => $this->app->make(FindStock::class)($variantId));
    }

    /**
     * @param  list<array{string, int}>  $lines
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    private function place(array $lines, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'Ada@Example.com', 'name' => 'Ada Lovelace'],
            'lines' => array_map(static fn (array $line): array => ['variant_id' => $line[0], 'quantity' => $line[1]], $lines),
            ...$overrides,
        ]);
    }

    private function markPaid(string $orderId): void
    {
        $this->asTenant(function () use ($orderId): void {
            $this->app->make(RequestPayment::class)($orderId);
            $this->app->make(RecordPayment::class)($orderId);
        });
    }

    #[Test]
    public function an_order_is_priced_by_the_catalog_and_reserves_its_stock(): void
    {
        Event::fake([OrderPlaced::class]);

        $response = $this->place([[$this->mug, 2], [$this->tee, 1]], [
            'shipping_address' => ['name' => 'Ada Lovelace', 'line1' => '12 St James Sq', 'city' => 'London', 'postal_code' => 'SW1Y 4JH', 'country' => 'gb'],
        ])
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.number', 1001)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.customer.email', 'ada@example.com')
            ->assertJsonPath('data.lines.0.sku', 'MUG-1')
            ->assertJsonPath('data.lines.0.name', 'Enamel Mug')
            ->assertJsonPath('data.lines.0.unit_price', '12.50')
            ->assertJsonPath('data.lines.0.line_total_minor', 2500)
            ->assertJsonPath('data.subtotal', '45.00')
            ->assertJsonPath('data.tax_minor', 0)
            ->assertJsonPath('data.total', '45.00')
            ->assertJsonPath('data.shipping_address.country', 'GB');

        $id = $response->json('data.id');
        $response->assertHeader('Location', route('api.v1.orders.show', ['order' => $id]));

        self::assertSame(3, $this->stock($this->mug)->available());
        self::assertSame(1, $this->stock($this->tee)->available());

        $this->getJson('/api/v1/orders/'.$id.'/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'placed')
            ->assertJsonPath('data.0.from_status', null)
            ->assertJsonPath('data.0.to_status', 'pending');

        // Same customer, however the address is typed; the next number.
        $this->place([[$this->mug, 1]], ['customer' => ['email' => 'ADA@example.COM']])
            ->assertCreated()
            ->assertJsonPath('data.number', 1002)
            ->assertJsonPath('data.customer.id', $response->json('data.customer.id'));

        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event): bool => $event->orderId === $id && $event->tenantId === $this->tenant->id);
    }

    #[Test]
    public function a_snapshot_keeps_the_price_the_order_was_placed_at(): void
    {
        $id = $this->place([[$this->mug, 1]])->json('data.id');

        $this->putJson('/api/v1/variants/'.$this->mug.'/prices/USD', ['amount' => '99.00'])->assertOk();

        $this->getJson('/api/v1/orders/'.$id)->assertJsonPath('data.lines.0.unit_price', '12.50')->assertJsonPath('data.total', '12.50');
    }

    #[Test]
    public function tax_is_computed_once_on_the_subtotal_by_the_configured_strategy(): void
    {
        config(['orders.tax_rate_basis_points' => 825]);
        $this->app->forgetInstance(TaxStrategy::class);

        // 3 × 12.50 = 37.50; 8.25% = 3.09375, rounded half away from zero.
        $this->place([[$this->mug, 3]])
            ->assertCreated()
            ->assertJsonPath('data.subtotal_minor', 3750)
            ->assertJsonPath('data.tax_minor', 309)
            ->assertJsonPath('data.total_minor', 4059);
    }

    #[Test]
    public function an_order_for_more_than_is_available_changes_nothing(): void
    {
        $this->place([[$this->mug, 4], [$this->tee, 3]])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:insufficient-stock')
            ->assertJsonPath('variant_id', $this->tee)
            ->assertJsonPath('available', 2);

        // The mug line was reserved before the tee line failed: rolled back.
        self::assertSame(0, $this->stock($this->mug)->reserved);
        self::assertSame(0, $this->stock($this->tee)->reserved);
        self::assertSame(0, $this->asTenant(static fn (): int => Order::query()->count()));

        // The failed placement did not consume an order number.
        $this->place([[$this->tee, 2]])->assertCreated()->assertJsonPath('data.number', 1001);
    }

    #[Test]
    public function lines_the_catalog_cannot_sell_are_rejected(): void
    {
        $draft = $this->asTenant(fn (): string => $this->variantOf(Product::factory()->draft()->withVariant('DRAFT-1')->create()));

        $this->place([[$this->mug, 1], ['01a20000-0000-7000-8000-000000000000', 1]])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'urn:problem-type:order-line-rejected')
            ->assertJsonPath('line', 2);
        $this->place([[$draft, 1]])->assertUnprocessable()->assertJsonPath('detail', 'Line 1: variant '.$draft.' belongs to a product that is not active.');
        $this->place([[$this->mug, 1]], ['currency' => 'EUR'])->assertUnprocessable()->assertJsonPath('detail', 'Line 1: variant '.$this->mug.' has no EUR price right now.');

        self::assertSame(0, $this->stock($this->mug)->reserved);
    }

    #[Test]
    public function the_request_is_validated(): void
    {
        $this->place([])->assertUnprocessable()->assertJsonStructure(['errors' => ['lines']]);
        $this->place([[$this->mug, 0]])->assertUnprocessable()->assertJsonStructure(['errors' => ['lines.0.quantity']]);
        $this->place([[$this->mug, 1]], ['customer_id' => '01a20000-0000-7000-8000-000000000000'])->assertUnprocessable();
        $this->place([[$this->mug, 1]], ['customer' => ['email' => 'not-an-address']])->assertUnprocessable()->assertJsonStructure(['errors' => ['customer.email']]);
        $this->place([[$this->mug, 1]], ['shipping_address' => ['name' => 'Ada']])->assertUnprocessable()->assertJsonStructure(['errors' => ['shipping_address.line1', 'shipping_address.country']]);
        $this->place([[$this->mug, 1]], ['currency' => 'XXX'])->assertUnprocessable()->assertJsonStructure(['errors' => ['currency']]);
    }

    #[Test]
    public function cancelling_returns_the_stock_and_is_final(): void
    {
        Event::fake([OrderCancelled::class]);
        $id = $this->place([[$this->mug, 2]])->json('data.id');

        $this->postJson('/api/v1/orders/'.$id.'/cancel', ['reason' => 'customer changed their mind'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_at', CarbonImmutable::now()->toIso8601String());

        self::assertSame(5, $this->stock($this->mug)->available());

        $this->postJson('/api/v1/orders/'.$id.'/cancel')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:illegal-transition')
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('allowed', []);

        $this->getJson('/api/v1/orders/'.$id.'/events')
            ->assertJsonPath('data.1.type', 'cancelled')
            ->assertJsonPath('data.1.from_status', 'pending')
            ->assertJsonPath('data.1.data.reason', 'customer changed their mind');

        Event::assertDispatchedTimes(OrderCancelled::class, 1);
    }

    #[Test]
    public function only_a_paid_order_ships_and_shipping_takes_its_units_out_of_stock(): void
    {
        Event::fake([OrderFulfilled::class]);
        $id = $this->place([[$this->mug, 2]])->json('data.id');

        $this->postJson('/api/v1/orders/'.$id.'/fulfil')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:illegal-transition')
            ->assertJsonPath('allowed', ['awaiting_payment', 'cancelled']);

        $this->markPaid($id);

        $this->postJson('/api/v1/orders/'.$id.'/fulfil', ['tracking_reference' => '1Z999AA10123456784'])
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled');

        $level = $this->stock($this->mug);
        self::assertSame(3, $level->onHand);
        self::assertSame(0, $level->reserved);

        $this->getJson('/api/v1/variants/'.$this->mug.'/stock-movements')->assertJsonPath('data.0.type', 'shipment')->assertJsonPath('data.0.reference', 'order:'.$id);
        Event::assertDispatched(OrderFulfilled::class);
    }

    #[Test]
    public function the_shipping_address_can_change_until_the_order_ships(): void
    {
        $id = $this->place([[$this->mug, 1]])->json('data.id');
        $address = ['name' => 'Ada', 'line1' => '1 Main St', 'city' => 'Leeds', 'postal_code' => 'LS1 1AA', 'country' => 'GB'];

        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', $address)
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.shipping_address.city', 'Leeds');

        // The same address again changes nothing.
        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', $address)->assertHeader('ETag', '"2"');
        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', ['city' => 'York'])->assertUnprocessable();

        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertOk();
        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', $address + ['line2' => 'Flat 2'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:shipping-address-locked');
    }

    #[Test]
    public function orders_are_listed_newest_first_and_filtered(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T10:00:00Z'));
        $first = $this->place([[$this->mug, 1]])->json('data.id');

        $this->travelTo(CarbonImmutable::parse('2026-11-22T10:00:00Z'));
        $second = $this->place([[$this->mug, 1]], ['customer' => ['email' => 'grace@example.com']]);
        $this->postJson('/api/v1/orders/'.$second->json('data.id').'/cancel')->assertOk();

        $ids = fn (string $query): array => array_column($this->getJson('/api/v1/orders'.$query)->assertOk()->json('data'), 'id');

        self::assertSame([$second->json('data.id'), $first], $ids(''));
        self::assertSame([$first], $ids('?status=pending'));
        self::assertSame([$second->json('data.id')], $ids('?customer_id='.$second->json('data.customer.id')));
        self::assertSame([$first], $ids('?placed_from=2026-11-20T00:00:00Z&placed_to=2026-11-21T00:00:00Z'));
        self::assertSame([$second->json('data.id')], $ids('?placed_from=2026-11-22T10:00:00Z'));

        $this->getJson('/api/v1/orders?status=shipped')->assertUnprocessable();
        $this->getJson('/api/v1/orders?placed_from=2026-11-20')->assertUnprocessable();
    }

    #[Test]
    public function access_follows_permissions_scopes_and_tenants(): void
    {
        $id = $this->place([[$this->mug, 1]])->json('data.id');

        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);
        $this->getJson('/api/v1/orders/'.$id)->assertOk();
        $this->place([[$this->mug, 1]])->assertForbidden();
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::OrdersRead]);
        $this->getJson('/api/v1/orders')->assertOk();
        $this->place([[$this->mug, 1]])->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::OrdersWrite]);
        $this->place([[$this->mug, 1]])->assertCreated()->assertJsonPath('data.number', 1002);

        $this->actingAsMember(Tenant::factory()->create());
        $this->getJson('/api/v1/orders/'.$id)->assertNotFound();
        $this->getJson('/api/v1/orders/'.$id.'/events')->assertNotFound();
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertNotFound();
        self::assertSame([], $this->getJson('/api/v1/orders')->json('data'));
    }
}
