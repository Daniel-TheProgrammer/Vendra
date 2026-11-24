<?php

declare(strict_types=1);

namespace App\Modules\Orders\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Orders\Application\Actions\PlaceOrder;
use App\Modules\Orders\Application\Commands\OrderLineInput;
use App\Modules\Orders\Application\Commands\PlaceOrderCommand;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Money\Currency;
use App\Shared\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RacesWorkers;
use Tests\TestCase;

/**
 * Concurrent placements for one tenant: every order that succeeds gets a
 * distinct number, the numbers have no gaps even though some placements fail,
 * and stock is never oversold.
 */
#[Group('database')]
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class OrderPlacementConcurrencyTest extends TestCase
{
    use RacesWorkers;

    private const WORKERS = 30;

    private const STOCK = 20;

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
    public function concurrent_orders_get_unique_gap_free_numbers_and_never_oversell(): void
    {
        $variantId = $this->context->runAs($this->tenantId, function (): string {
            $product = Product::factory()->withVariant('RACE-1', 500)->create();
            $variantId = ProductVariant::query()->where('product_id', $product->id)->valueOrFail('id');
            $this->app->make(AdjustStock::class)(new AdjustStockCommand($variantId, self::STOCK, AdjustmentReason::Received));

            return $variantId;
        });

        $results = $this->race(self::WORKERS, function (int $worker) use ($variantId): string {
            try {
                $this->context->runAs($this->tenantId, fn (): Order => $this->app->make(PlaceOrder::class)(new PlaceOrderCommand(
                    currency: Currency::USD,
                    lines: [new OrderLineInput($variantId, 1)],
                    customerEmail: 'customer'.($worker % 3).'@example.com',
                )));

                return 'placed';
            } catch (InsufficientStock) {
                return 'refused';
            }
        });

        self::assertSame(['placed' => self::STOCK, 'refused' => self::WORKERS - self::STOCK], $this->tally($results), implode("\n", $results));

        $this->context->runAs($this->tenantId, function () use ($variantId): void {
            self::assertSame(range(1001, 1000 + self::STOCK), Order::query()->orderBy('number')->pluck('number')->all());
            self::assertSame(0, $this->app->make(FindStock::class)($variantId)->available());
        });
    }
}
