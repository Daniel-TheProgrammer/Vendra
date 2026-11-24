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
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Orders\Application\Actions\RecordPayment;
use App\Modules\Orders\Application\Actions\RefundOrder;
use App\Modules\Orders\Application\Actions\RequestPayment;
use App\Modules\Orders\Domain\Events\OrderPaid;
use App\Modules\Orders\Domain\Events\OrderRefunded;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * An order's whole life, and optimistic locking on every write to it.
 */
#[Group('database')]
final class OrderLifecycleTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    private string $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->variant = $this->asTenant(function (): string {
            $product = Product::factory()->withVariant('LAMP-1', 4900)->create();
            $variant = ProductVariant::query()->where('product_id', $product->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variant, 10, AdjustmentReason::Received));

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

    private function stock(): StockLevel
    {
        return $this->asTenant(fn (): StockLevel => $this->app->make(FindStock::class)($this->variant));
    }

    private function place(int $quantity = 2): string
    {
        return $this->postJson('/api/v1/orders', [
            'currency' => 'USD',
            'customer' => ['email' => 'buyer@example.com'],
            'lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]],
        ])->assertCreated()->json('data.id');
    }

    private function pay(string $orderId): void
    {
        $this->asTenant(function () use ($orderId): void {
            $this->app->make(RequestPayment::class)($orderId, 'pi_123');
            $this->app->make(RecordPayment::class)($orderId, 'pi_123');
        });
    }

    #[Test]
    public function an_order_goes_from_placed_to_paid_to_shipped_to_refunded(): void
    {
        Event::fake([OrderPaid::class, OrderRefunded::class]);
        $id = $this->place();

        $this->pay($id);
        $this->getJson('/api/v1/orders/'.$id)->assertJsonPath('data.status', 'paid')->assertHeader('ETag', '"3"');

        $this->postJson('/api/v1/orders/'.$id.'/fulfil', [], ['If-Match' => '"3"'])->assertOk()->assertJsonPath('data.status', 'fulfilled');
        self::assertSame(8, $this->stock()->onHand);

        $this->asTenant(fn () => $this->app->make(RefundOrder::class)($id, 're_456'));

        // Shipped units are not put back by a refund; a return is a stock adjustment.
        $afterRefund = $this->stock();
        self::assertSame(8, $afterRefund->onHand);
        self::assertSame(0, $afterRefund->reserved);

        $history = $this->getJson('/api/v1/orders/'.$id.'/events')->json('data');
        self::assertSame(['placed', 'awaiting_payment', 'paid', 'fulfilled', 'refunded'], array_column($history, 'type'));
        self::assertSame([null, 'pending', 'awaiting_payment', 'paid', 'fulfilled'], array_column($history, 'from_status'));
        self::assertSame('pi_123', $history[2]['data']['payment_reference']);

        Event::assertDispatched(OrderPaid::class);
        Event::assertDispatched(OrderRefunded::class);
    }

    #[Test]
    public function refunding_a_paid_order_before_it_ships_releases_its_stock(): void
    {
        $id = $this->place(3);
        $this->pay($id);
        self::assertSame(3, $this->stock()->reserved);

        $this->asTenant(fn () => $this->app->make(RefundOrder::class)($id));

        self::assertSame(10, $this->stock()->available());
        $this->getJson('/api/v1/orders/'.$id)->assertJsonPath('data.status', 'refunded');
        $this->postJson('/api/v1/orders/'.$id.'/cancel')->assertConflict()->assertJsonPath('allowed', []);
    }

    #[Test]
    public function a_paid_order_cannot_be_cancelled_only_refunded(): void
    {
        $id = $this->place();
        $this->pay($id);

        $this->postJson('/api/v1/orders/'.$id.'/cancel')
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:illegal-transition')
            ->assertJsonPath('allowed', ['fulfilled', 'refunded']);
    }

    #[Test]
    public function a_write_based_on_a_stale_copy_is_refused_and_changes_nothing(): void
    {
        $id = $this->place();
        $address = ['name' => 'B', 'line1' => '1 Road', 'city' => 'Bath', 'postal_code' => 'BA1 1AA', 'country' => 'GB'];

        // Someone else changes the order after we read version 1.
        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', $address, ['If-Match' => '"1"'])->assertOk()->assertHeader('ETag', '"2"');

        $this->postJson('/api/v1/orders/'.$id.'/cancel', [], ['If-Match' => '"1"'])
            ->assertStatus(412)
            ->assertJsonPath('type', 'urn:problem-type:precondition-failed')
            ->assertJsonPath('current_version', 2);
        $this->putJson('/api/v1/orders/'.$id.'/shipping-address', [...$address, 'city' => 'York'], ['If-Match' => '"1"'])->assertStatus(412);

        self::assertSame(2, $this->stock()->reserved, 'the refused cancel released nothing');
        $this->getJson('/api/v1/orders/'.$id)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.shipping_address.city', 'Bath');

        $this->postJson('/api/v1/orders/'.$id.'/cancel', [], ['If-Match' => 'v2'])->assertStatus(400);
        $this->postJson('/api/v1/orders/'.$id.'/cancel', [], ['If-Match' => '"2"'])->assertOk()->assertHeader('ETag', '"3"');
    }
}
