<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Application\Actions\AdjustStock;
use App\Modules\Catalog\Application\Actions\ReleaseStock;
use App\Modules\Catalog\Application\Actions\ReserveStock;
use App\Modules\Catalog\Application\Commands\AdjustStockCommand;
use App\Modules\Catalog\Application\Commands\StockLine;
use App\Modules\Catalog\Application\Queries\FindStock;
use App\Modules\Catalog\Domain\AdjustmentReason;
use App\Modules\Catalog\Domain\InsufficientStock;
use App\Modules\Catalog\Domain\StockNotReserved;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Catalog\Infrastructure\Models\StockMovement;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Domain\Exception\EntityNotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Reservation semantics, exercised through the actions the order flow uses.
 */
#[Group('database')]
final class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private string $mug;

    private string $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = $this->app->make(TenantContext::class);
        $this->context->set(Tenant::factory()->create()->id);

        $product = Product::factory()->withVariant('MUG-1')->withVariant('TEE-1')->create();
        [$this->mug, $this->tee] = $product->variants->map(static fn (ProductVariant $variant): string => $variant->id)->all();

        $this->receive($this->mug, 5);
        $this->receive($this->tee, 2);
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        parent::tearDown();
    }

    private function receive(string $variantId, int $quantity): void
    {
        $this->app->make(AdjustStock::class)(new AdjustStockCommand($variantId, $quantity, AdjustmentReason::Received));
    }

    /**
     * @param  non-empty-list<StockLine>  $lines
     */
    private function reserve(array $lines, string $reference = 'order-1'): void
    {
        $this->app->make(ReserveStock::class)($lines, $reference);
    }

    private function available(string $variantId): int
    {
        return $this->app->make(FindStock::class)($variantId)->available();
    }

    #[Test]
    public function an_order_reserves_every_line_and_records_the_reference(): void
    {
        $this->reserve([new StockLine($this->mug, 2), new StockLine($this->tee, 1), new StockLine($this->mug, 1)]);

        self::assertSame(2, $this->available($this->mug));
        self::assertSame(1, $this->available($this->tee));

        // Duplicate lines are summed into one movement per variant.
        $reserved = StockMovement::query()->where('reference', 'order-1')->pluck('quantity', 'variant_id')->all();

        self::assertCount(2, $reserved);
        self::assertSame(3, $reserved[$this->mug]);
        self::assertSame(1, $reserved[$this->tee]);
    }

    #[Test]
    public function a_short_line_refuses_the_whole_order(): void
    {
        try {
            $this->reserve([new StockLine($this->mug, 2), new StockLine($this->tee, 3)]);
            self::fail('The order must be refused.');
        } catch (InsufficientStock $exception) {
            self::assertSame($this->tee, $exception->extensions()['variant_id']);
        }

        self::assertSame(5, $this->available($this->mug), 'nothing may be held for a refused order');
        self::assertSame(0, StockMovement::query()->where('reference', 'order-1')->count());
    }

    #[Test]
    public function a_reservation_rolls_back_with_the_callers_transaction(): void
    {
        try {
            DB::transaction(function (): void {
                $this->reserve([new StockLine($this->mug, 5)]);

                throw new RuntimeException('payment authorisation failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(5, $this->available($this->mug));
    }

    #[Test]
    public function releasing_returns_stock_but_never_more_than_was_held(): void
    {
        $this->reserve([new StockLine($this->mug, 3)]);
        $release = $this->app->make(ReleaseStock::class);

        $release([new StockLine($this->mug, 2)], 'order-1');
        self::assertSame(4, $this->available($this->mug));

        $this->expectException(StockNotReserved::class);
        $release([new StockLine($this->mug, 2)], 'order-1');
    }

    #[Test]
    public function unknown_variants_are_rejected(): void
    {
        $this->expectException(EntityNotFound::class);

        $this->reserve([new StockLine('01a20000-0000-7000-8000-000000000000', 1)]);
    }
}
