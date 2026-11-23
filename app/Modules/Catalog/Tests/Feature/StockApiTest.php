<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Application\Actions\ReserveStock;
use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Domain\Events\StockAdjusted;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
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
final class StockApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private string $variantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->variantId = $this->context->runAs($this->tenant->id, static function (): string {
            $product = Product::factory()->withVariant('MUG-1')->create();

            return ProductVariant::query()->where('product_id', $product->id)->valueOrFail('id');
        });
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function adjust(int $quantity, string $reason = 'received', ?string $note = null): TestResponse
    {
        return $this->postJson('/api/v1/variants/'.$this->variantId.'/stock-adjustments', array_filter([
            'quantity' => $quantity,
            'reason' => $reason,
            'note' => $note,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function reserve(int $quantity, string $reference): void
    {
        $this->context->runAs($this->tenant->id, fn () => $this->app->make(ReserveStock::class)([new StockLine($this->variantId, $quantity)], $reference));
    }

    #[Test]
    public function a_variant_without_movements_has_no_stock(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);

        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock')
            ->assertOk()
            ->assertExactJson(['data' => ['variant_id' => $this->variantId, 'on_hand' => 0, 'reserved' => 0, 'available' => 0]]);
    }

    #[Test]
    public function receiving_and_writing_off_stock_is_recorded_as_movements(): void
    {
        Event::fake([StockAdjusted::class]);
        $user = $this->actingAsMember($this->tenant, MembershipRole::Staff);

        $this->adjust(24, 'received', 'PO-1042')->assertCreated()->assertJsonPath('data.on_hand', 24)->assertJsonPath('data.available', 24);
        $this->adjust(-2, 'damaged')->assertCreated()->assertJsonPath('data.on_hand', 22);

        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock-movements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.quantity', -2)
            ->assertJsonPath('data.0.reason', 'damaged')
            ->assertJsonPath('data.0.on_hand_after', 22)
            ->assertJsonPath('data.1.note', 'PO-1042')
            ->assertJsonPath('data.1.actor_id', $user->id);

        Event::assertDispatched(StockAdjusted::class, fn (StockAdjusted $event): bool => $event->delta === -2 && $event->onHand === 22 && $event->tenantId === $this->tenant->id);
    }

    #[Test]
    public function reserved_units_cannot_be_written_off(): void
    {
        $this->actingAsMember($this->tenant);
        $this->adjust(10)->assertCreated();
        $this->reserve(4, 'order-1');

        $this->getJson('/api/v1/products/'.$this->productId())
            ->assertOk()
            ->assertJsonPath('data.variants.0.inventory', ['on_hand' => 10, 'reserved' => 4, 'available' => 6]);

        $this->adjust(-7, 'lost')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:insufficient-stock')
            ->assertJsonPath('available', 6)
            ->assertJsonPath('requested', 7);

        $this->adjust(-6, 'lost')->assertCreated()->assertJsonPath('data', [
            'variant_id' => $this->variantId, 'on_hand' => 4, 'reserved' => 4, 'available' => 0,
        ]);
    }

    #[Test]
    public function adjustments_are_validated(): void
    {
        $this->actingAsMember($this->tenant);

        $this->adjust(0)->assertUnprocessable()->assertJsonStructure(['errors' => ['quantity']]);
        $this->adjust(5, 'found-behind-sofa')->assertUnprocessable()->assertJsonStructure(['errors' => ['reason']]);
        $this->adjust(2_000_000)->assertUnprocessable();
        $this->postJson('/api/v1/variants/'.$this->variantId.'/stock-adjustments', ['quantity' => '1.5', 'reason' => 'received'])->assertUnprocessable();
    }

    #[Test]
    public function permissions_and_scopes_apply_to_stock(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);
        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock-movements')->assertOk();
        $this->adjust(1)->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::CatalogRead]);
        $this->adjust(1)->assertForbidden();

        $issued = $this->actingAsApiKey($this->tenant, [ApiScope::CatalogWrite]);
        $this->adjust(3)->assertCreated();

        $this->actingAsMember($this->tenant);
        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock-movements')->assertJsonPath('data.0.actor_id', $issued->key->id);
    }

    #[Test]
    public function another_tenants_variant_does_not_exist_for_you(): void
    {
        $this->actingAsMember(Tenant::factory()->create());

        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock')->assertNotFound();
        $this->getJson('/api/v1/variants/'.$this->variantId.'/stock-movements')->assertNotFound();
        $this->adjust(5)->assertNotFound();
    }

    #[Test]
    public function the_database_refuses_to_rewrite_stock_history(): void
    {
        $this->actingAsMember($this->tenant);
        $this->adjust(5)->assertCreated();

        $this->context->runAs($this->tenant->id, function (): void {
            foreach (['UPDATE stock_movements SET quantity = 500', 'DELETE FROM stock_movements'] as $statement) {
                try {
                    DB::transaction(static fn () => DB::statement($statement));
                    self::fail($statement.' must be rejected');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('append-only', $exception->getMessage());
                }
            }
        });
    }

    #[Test]
    public function the_database_refuses_an_impossible_level(): void
    {
        $this->actingAsMember($this->tenant);
        $this->adjust(5)->assertCreated();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('inventory_levels_quantities_check');

        $this->context->runAs($this->tenant->id, static fn () => DB::transaction(static fn () => DB::table('inventory_levels')->update(['reserved' => 6])));
    }

    private function productId(): string
    {
        return $this->context->runAs($this->tenant->id, fn (): string => ProductVariant::query()->whereKey($this->variantId)->valueOrFail('product_id'));
    }
}
