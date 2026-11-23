<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Application\Queries\FindVariantPrice;
use App\Modules\Catalog\Domain\Events\PriceChanged;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\VariantPrice;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class ScheduledPriceApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-23T12:00:00Z'));
        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->variantId = $this->context->runAs($this->tenant->id, static function (): string {
            $product = Product::factory()->withVariant('MUG-1', 2000)->create();

            return ProductVariant::query()->where('product_id', $product->id)->valueOrFail('id');
        });
        $this->actingAsMember($this->tenant, MembershipRole::Staff);
    }

    /**
     * @param  array<string, string|null>  $window
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    private function schedule(string $amount, array $window, string $currency = 'USD', array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/variants/'.$this->variantId.'/scheduled-prices', ['currency' => $currency, 'amount' => $amount, ...$window], $headers);
    }

    private function product(): string
    {
        return '/api/v1/products/'.$this->context->runAs($this->tenant->id, fn (): string => ProductVariant::query()->whereKey($this->variantId)->valueOrFail('product_id'));
    }

    #[Test]
    public function a_sale_overrides_the_base_price_only_while_its_window_is_open(): void
    {
        Event::fake([PriceChanged::class]);

        $response = $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00-05:00', 'valid_to' => '2026-12-01T00:00:00-05:00'], headers: ['If-Match' => '"1"'])
            ->assertCreated()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.variants.0.prices', [['currency' => 'USD', 'amount' => '20.00', 'amount_minor' => 2000]])
            ->assertJsonPath('data.variants.0.scheduled_prices.0.amount', '15.00')
            ->assertJsonPath('data.variants.0.scheduled_prices.0.valid_from', '2026-11-27T05:00:00+00:00')
            ->assertJsonPath('data.variants.0.scheduled_prices.0.valid_to', '2026-12-01T05:00:00+00:00');

        $priceId = $response->json('data.variants.0.scheduled_prices.0.id');
        $response->assertHeader('Location', route('api.v1.variants.scheduled-prices.destroy', ['variant' => $this->variantId, 'price' => $priceId]));

        $this->travelTo(CarbonImmutable::parse('2026-11-27T05:00:00Z'));
        $this->getJson($this->product())->assertJsonPath('data.variants.0.prices.0.amount_minor', 1500);

        $this->travelTo(CarbonImmutable::parse('2026-12-01T05:00:00Z'));
        $this->getJson($this->product())
            ->assertJsonPath('data.variants.0.prices.0.amount_minor', 2000)
            ->assertJsonPath('data.variants.0.scheduled_prices', []);

        Event::assertDispatched(PriceChanged::class, fn (PriceChanged $event): bool => $event->variantId === $this->variantId && $event->currency === Currency::USD && $event->version === 2);
    }

    #[Test]
    public function order_placement_reads_the_price_in_effect_at_an_instant(): void
    {
        $this->schedule('25.00', ['valid_from' => '2027-01-01T00:00:00Z'])->assertCreated();

        $this->context->runAs($this->tenant->id, function (): void {
            $find = $this->app->make(FindVariantPrice::class);

            self::assertSame(2000, $find($this->variantId, Currency::USD)?->amount->amount);
            self::assertSame(2500, $find($this->variantId, Currency::USD, CarbonImmutable::parse('2027-01-01T00:00:00Z'))?->amount->amount);
            self::assertNull($find($this->variantId, Currency::EUR));
        });
    }

    #[Test]
    public function overlapping_windows_are_a_conflict_but_back_to_back_ones_are_fine(): void
    {
        $existing = $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z', 'valid_to' => '2026-12-01T00:00:00Z'])
            ->assertCreated()
            ->json('data.variants.0.scheduled_prices.0.id');

        $this->schedule('14.00', ['valid_from' => '2026-11-30T00:00:00Z', 'valid_to' => '2026-12-05T00:00:00Z'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:price-window-overlap')
            ->assertJsonPath('conflicting_price_id', $existing);

        $this->schedule('17.00', ['valid_from' => '2026-12-01T00:00:00Z', 'valid_to' => '2026-12-24T00:00:00Z'])->assertCreated();
        $this->schedule('13.00', ['valid_from' => '2026-11-30T00:00:00Z', 'valid_to' => '2026-12-05T00:00:00Z'], 'EUR')->assertCreated();
    }

    #[Test]
    public function the_database_refuses_overlaps_and_a_second_base_price_on_its_own(): void
    {
        $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z', 'valid_to' => '2026-12-01T00:00:00Z'])->assertCreated();

        $this->context->runAs($this->tenant->id, function (): void {
            $insert = fn (array $row) => DB::transaction(fn () => VariantPrice::query()->create(['variant_id' => $this->variantId, 'currency' => 'USD', 'amount' => 1, ...$row]));

            foreach ([
                'variant_prices_no_overlap' => ['valid_from' => '2026-11-30 00:00:00+00', 'valid_to' => null],
                'variant_prices_base_unique' => [],
                'variant_prices_window_check' => ['valid_from' => '2026-12-05 00:00:00+00', 'valid_to' => '2026-12-04 00:00:00+00'],
            ] as $constraint => $row) {
                try {
                    $insert($row);
                    self::fail($constraint.' must refuse the row');
                } catch (QueryException $exception) {
                    self::assertStringContainsString($constraint, $exception->getMessage());
                }
            }
        });
    }

    #[Test]
    public function cancelling_a_running_sale_restores_the_base_price_immediately(): void
    {
        $priceId = $this->schedule('15.00', ['valid_to' => '2026-11-30T00:00:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.variants.0.prices.0.amount_minor', 1500)
            ->json('data.variants.0.scheduled_prices.0.id');

        $this->deleteJson('/api/v1/variants/'.$this->variantId.'/scheduled-prices/'.$priceId)
            ->assertOk()
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.variants.0.prices.0.amount_minor', 2000)
            ->assertJsonPath('data.variants.0.scheduled_prices', []);

        $this->deleteJson('/api/v1/variants/'.$this->variantId.'/scheduled-prices/'.$priceId)->assertNotFound();
    }

    #[Test]
    public function base_price_endpoints_never_touch_scheduled_prices(): void
    {
        $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z', 'valid_to' => '2026-12-01T00:00:00Z'])->assertCreated();

        $this->putJson('/api/v1/variants/'.$this->variantId.'/prices/USD', ['amount' => '22.00'])
            ->assertOk()
            ->assertJsonPath('data.variants.0.prices.0.amount_minor', 2200)
            ->assertJsonCount(1, 'data.variants.0.scheduled_prices');

        $this->deleteJson('/api/v1/variants/'.$this->variantId.'/prices/USD')
            ->assertOk()
            ->assertJsonPath('data.variants.0.prices', [])
            ->assertJsonCount(1, 'data.variants.0.scheduled_prices');

        // A base price row id is not a scheduled price.
        $this->putJson('/api/v1/variants/'.$this->variantId.'/prices/EUR', ['amount' => '18.00'])->assertOk();
        $baseId = $this->context->runAs($this->tenant->id, fn (): string => VariantPrice::query()->where('variant_id', $this->variantId)->whereNull('valid_from')->whereNull('valid_to')->valueOrFail('id'));
        $this->deleteJson('/api/v1/variants/'.$this->variantId.'/scheduled-prices/'.$baseId)->assertNotFound();
    }

    #[Test]
    public function the_window_is_validated(): void
    {
        $this->schedule('15.00', [])->assertUnprocessable()->assertJsonStructure(['errors' => ['valid_from', 'valid_to']]);
        $this->schedule('15.00', ['valid_from' => '2026-12-01T00:00:00Z', 'valid_to' => '2026-11-27T00:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.valid_to.0', 'The window must end after it starts.');
        $this->schedule('15.00', ['valid_to' => '2026-11-23T12:00:00Z'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.valid_to.0', 'A scheduled price cannot end in the past.');
        $this->schedule('15.00', ['valid_from' => '2026-11-27'])->assertUnprocessable()->assertJsonStructure(['errors' => ['valid_from']]);
        $this->schedule('1.5', ['valid_from' => '2026-11-27T00:00:00Z'], 'JPY')->assertUnprocessable()->assertJsonStructure(['errors' => ['amount']]);
        $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z'], 'XXX')->assertUnprocessable()->assertJsonStructure(['errors' => ['currency']]);
    }

    #[Test]
    public function scheduling_needs_catalog_manage_and_respects_tenants(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);
        $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z'])->assertForbidden();

        $this->actingAsMember(Tenant::factory()->create());
        $this->schedule('15.00', ['valid_from' => '2026-11-27T00:00:00Z'])->assertNotFound();
    }
}
