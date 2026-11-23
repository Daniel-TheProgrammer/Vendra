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
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency, not simulated: separate processes with their own database
 * connections race for the same rows, so the row locks are what is under
 * test. Data must be committed for the workers to see it, so this test does
 * not run inside RefreshDatabase's transaction; it cleans up after itself.
 */
#[Group('database')]
#[Group('concurrency')]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class StockReservationConcurrencyTest extends TestCase
{
    private const WORKERS = 50;

    private const STOCK = 10;

    private TenantContext $context;

    private string $tenantId;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->context = $this->app->make(TenantContext::class);
        $this->tenantId = Tenant::factory()->create()->id;
        $this->workDir = sys_get_temp_dir().'/vendra-race-'.bin2hex(random_bytes(6));
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        // TRUNCATE is not subject to RLS or the append-only trigger, and
        // CASCADE clears every tenant-owned row this test committed.
        DB::statement('TRUNCATE tenants CASCADE');

        array_map(unlink(...), glob($this->workDir.'/*') ?: []);
        rmdir($this->workDir);

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
        $results = $this->race(function (int $worker) use ($mug, $tee): string {
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

    /**
     * Forks one process per worker, releases them together and collects what
     * each returned (or the error it died with).
     *
     * @param  callable(int): string  $work
     * @return list<string>
     */
    private function race(callable $work): array
    {
        // Children must not share the parent's socket to the database.
        DB::disconnect();
        $start = $this->workDir.'/start';
        $pids = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                $this->runWorker($worker, $work, $start);
            }

            $pids[] = $pid;
        }

        touch($start);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $file = $this->workDir.'/'.$worker;
            $results[] = is_file($file) ? (string) file_get_contents($file) : 'no result from worker '.$worker;
        }

        return $results;
    }

    /**
     * Never returns. The worker ends with SIGKILL so that no PHPUnit or
     * framework shutdown code runs in the copy of the parent's process.
     *
     * @param  callable(int): string  $work
     */
    private function runWorker(int $worker, callable $work, string $start): never
    {
        try {
            DB::purge();

            while (! is_file($start)) {
                usleep(1_000);
            }

            $result = $work($worker);
        } catch (Throwable $exception) {
            $result = sprintf('error in worker %d: %s: %s', $worker, $exception::class, $exception->getMessage());
        }

        file_put_contents($this->workDir.'/'.$worker, $result);
        DB::disconnect();
        posix_kill((int) getmypid(), SIGKILL);

        exit(1);
    }

    /**
     * @param  list<string>  $results
     * @return array<string, int>
     */
    private function tally(array $results): array
    {
        $counts = array_count_values($results);
        ksort($counts);

        return $counts;
    }
}
