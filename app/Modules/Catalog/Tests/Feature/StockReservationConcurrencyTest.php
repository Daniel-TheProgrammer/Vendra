<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Actions\ReserveStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\StockMovement;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RacesWorkers;
use Tests\TestCase;

/**
 * Real concurrency, not simulated: separate processes with their own database
 * connections race for the same rows, so the row locks are what is under test.
 */
#[Group('database')]
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class StockReservationConcurrencyTest extends TestCase
{
    use RacesWorkers;

    private const WORKERS = 50;

    private const STOCK = 10;

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
    public function fifty_concurrent_orders_never_oversell_ten_units(): void
    {
        [$mug, $tee] = $this->context->runAs($this->tenantId, function (): array {
            $product = Product::factory()->withVariant('RACE-MUG')->withVariant('RACE-TEE')->create();
            $ids = $product->variants->map(static fn (ProductVariant $variant): string => $variant->id)->all();

            foreach ($ids as $variantId) {
                $this->app->make(AdjustStock::class)(new AdjustStockCommand($variantId, self::STOCK, AdjustmentReason::Received));
            }

            return $ids;
        });

        // Half the workers list the variants in the opposite order. Without
        // consistent lock ordering that is a textbook deadlock.
        $results = $this->race(self::WORKERS, function (int $worker) use ($mug, $tee): string {
            $lines = $worker % 2 === 0
                ? [new StockLine($mug, 1), new StockLine($tee, 1)]
                : [new StockLine($tee, 1), new StockLine($mug, 1)];

            try {
                $this->context->runAs($this->tenantId, fn () => $this->app->make(ReserveStock::class)($lines, 'order-'.$worker));

                return 'reserved';
            } catch (InsufficientStock) {
                return 'refused';
            }
        });

        self::assertSame(['refused' => self::WORKERS - self::STOCK, 'reserved' => self::STOCK], $this->tally($results), implode("\n", $results));

        $this->context->runAs($this->tenantId, function () use ($mug, $tee): void {
            foreach ([$mug, $tee] as $variantId) {
                $level = $this->app->make(FindStock::class)($variantId);

                self::assertSame(self::STOCK, $level->reserved);
                self::assertSame(0, $level->available());
                self::assertSame(self::STOCK, StockMovement::query()->where('variant_id', $variantId)->where('type', 'reservation')->count());
            }
        });
    }
}
