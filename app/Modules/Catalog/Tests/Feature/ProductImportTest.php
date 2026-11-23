<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Application\Import\RunProductImport;
use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Domain\ImportStatus;
use App\Modules\Catalog\Infrastructure\Jobs\ProcessProductImport;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Catalog\Infrastructure\Models\ProductImport;
use App\Modules\Catalog\Infrastructure\Models\ProductImportGroup;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class ProductImportTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->tenant = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
        $this->actingAsMember($this->tenant, MembershipRole::Staff);
    }

    /**
     * @param  list<list<string>>  $rows
     * @return TestResponse<JsonResponse>
     */
    private function upload(array $rows, string $header = 'handle,name,description,status,sku,variant_name,options,price_usd,price_jpy'): TestResponse
    {
        $lines = [$header, ...array_map(static fn (array $row): string => implode(',', $row), $rows)];

        return $this->post('/api/v1/products/import', [
            'file' => UploadedFile::fake()->createWithContent('catalog.csv', implode("\r\n", $lines)."\r\n"),
        ], ['Accept' => 'application/json']);
    }

    /**
     * Five products, one variant each.
     *
     * @return list<list<string>>
     */
    private function fiveProducts(): array
    {
        return array_map(
            static fn (int $n): array => ['p'.$n, 'Product '.$n, '', 'active', 'SKU-'.$n, '', '', '10.00', ''],
            range(1, 5),
        );
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

    #[Test]
    public function a_csv_is_imported_in_the_background_with_per_row_errors(): void
    {
        $response = $this->upload([
            ['tee', 'Organic Tee', 'Heavyweight', 'active', 'tee-red-m', 'Red / M', 'colour=red;size=M', '19.99', '2900'],
            ['tee', '', '', '', 'TEE-RED-L', 'Red / L', 'colour=red;size=L', '21.00', ''],
            ['mug', 'Enamel Mug', '', '', 'MUG-1', '', '', '12.5', ''],
            ['bad', 'Broken', '', 'published', 'BAD 1', '', 'colour', '1.999', '1.5'],
            ['dupe', 'Duplicate SKU', '', '', 'MUG-1', '', '', '', ''],
        ])->assertStatus(202)->assertJsonPath('data.total_rows', 5);

        $id = $response->json('data.id');
        $response->assertHeader('Location', route('api.v1.imports.show', ['import' => $id]));

        // The sync queue in tests has already run the job.
        $this->getJson('/api/v1/imports/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed_rows', 5)
            ->assertJsonPath('data.failed_rows', 2)
            ->assertJsonPath('data.created_products', 2)
            ->assertJsonPath('data.percent_complete', 100)
            ->assertJsonPath('data.errors.0.row', 5)
            ->assertJsonPath('data.errors.0.handle', 'bad')
            ->assertJsonPath('data.errors.0.message', 'status must be one of: draft, active.')
            ->assertJsonCount(6, 'data.errors')
            ->assertJsonPath('data.errors.5', ['row' => 6, 'handle' => 'dupe', 'message' => 'A variant with SKU "MUG-1" already exists.']);

        $tee = $this->getJson('/api/v1/products?sku=TEE-RED-M')->assertOk()->json('data.0');
        self::assertSame('Organic Tee', $tee['name']);
        self::assertSame('active', $tee['status']);
        self::assertSame(['TEE-RED-M', 'TEE-RED-L'], array_column($tee['variants'], 'sku'));
        // jsonb keeps keys, not their order.
        self::assertEquals(['colour' => 'red', 'size' => 'M'], $tee['variants'][0]['options']);
        self::assertSame([['currency' => 'JPY', 'amount' => '2900', 'amount_minor' => 2900], ['currency' => 'USD', 'amount' => '19.99', 'amount_minor' => 1999]], $tee['variants'][0]['prices']);

        $this->getJson('/api/v1/products?sku=MUG-1')->assertJsonPath('data.0.status', 'draft')->assertJsonPath('data.0.variants.0.prices.0.amount', '12.50');
    }

    #[Test]
    public function an_import_interrupted_mid_file_resumes_without_duplicates(): void
    {
        Queue::fake();
        $id = $this->upload($this->fiveProducts())->assertStatus(202)->json('data.id');
        Queue::assertPushed(ProcessProductImport::class, fn (ProcessProductImport $job): bool => $job->importId === $id && $job->tenantId === $this->tenant->id);

        // The worker dies while creating the third product.
        $created = 0;
        Event::listen(ProductCreated::class, static function () use (&$created): void {
            if (++$created === 3) {
                throw new RuntimeException('worker killed');
            }
        });

        try {
            $this->asTenant(fn () => $this->app->make(RunProductImport::class)($id));
            self::fail('The simulated crash must surface.');
        } catch (RuntimeException $crash) {
            self::assertSame('worker killed', $crash->getMessage());
        }

        $this->asTenant(function () use ($id): void {
            $import = ProductImport::query()->whereKey($id)->firstOrFail();
            self::assertSame(ImportStatus::Processing, $import->status);
            self::assertSame(2, $import->processed_rows, 'progress counts exactly the committed groups');
            self::assertSame(2, Product::query()->count(), 'the interrupted group left nothing behind');
        });

        // A fresh worker picks the import up again.
        $this->asTenant(fn () => $this->app->make(RunProductImport::class)($id));

        $this->asTenant(function () use ($id): void {
            $import = ProductImport::query()->whereKey($id)->firstOrFail();
            self::assertSame(ImportStatus::Completed, $import->status);
            self::assertSame(5, $import->processed_rows);
            self::assertSame(5, $import->created_products);
            self::assertSame(0, $import->failed_rows);
            self::assertSame(5, Product::query()->count());
            self::assertSame(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5'], ProductVariant::query()->orderBy('sku')->pluck('sku')->all());
            self::assertSame(5, ProductImportGroup::query()->where('import_id', $id)->count());
        });

        // Running a finished import again is a no-op.
        $this->asTenant(fn () => $this->app->make(RunProductImport::class)($id));
        self::assertSame(5, $this->asTenant(static fn (): int => Product::query()->count()));
    }

    #[Test]
    public function large_files_are_imported_in_batches_that_each_persist_progress(): void
    {
        Queue::fake();
        $id = $this->upload($this->fiveProducts())->json('data.id');
        $run = $this->app->make(RunProductImport::class);

        self::assertFalse($this->asTenant(static fn (): bool => $run($id, 2)));
        self::assertSame(2, $this->asTenant(static fn (): int => ProductImport::query()->whereKey($id)->firstOrFail()->processed_rows));
        self::assertFalse($this->asTenant(static fn (): bool => $run($id, 2)));
        self::assertTrue($this->asTenant(static fn (): bool => $run($id, 2)));

        $this->getJson('/api/v1/imports/'.$id)->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_products', 5);
    }

    #[Test]
    public function a_file_that_cannot_be_imported_is_rejected_before_anything_is_queued(): void
    {
        Queue::fake();

        $this->upload([['tee', 'Tee', 'TEE-1']], 'handle,name,sku,prise_usd')
            ->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'The header row is invalid: unknown column(s): prise_usd.');
        $this->upload([], 'handle,name,sku')->assertUnprocessable()->assertJsonPath('errors.file.0', 'The file has a header but no rows to import.');
        $this->upload([['x']], 'handle,name')->assertUnprocessable()->assertJsonPath('errors.file.0', 'The header row is invalid: missing column(s): sku.');
        $this->post('/api/v1/products/import', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['errors' => ['file']]);
        $this->post('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('cat.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64))], ['Accept' => 'application/json'])->assertUnprocessable();

        Queue::assertNothingPushed();
        self::assertSame(0, $this->asTenant(static fn (): int => ProductImport::query()->count()));
    }

    #[Test]
    public function a_byte_order_mark_and_upper_case_headers_are_tolerated(): void
    {
        $this->upload([['p1', 'Product 1', 'SKU-1', '5']], "\u{FEFF}Handle,Name,SKU,Price_USD")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        $this->getJson('/api/v1/products?sku=SKU-1')->assertJsonPath('data.0.variants.0.prices.0.amount_minor', 500);
    }

    #[Test]
    public function imports_belong_to_their_tenant_and_need_catalog_manage(): void
    {
        Queue::fake();
        $id = $this->upload($this->fiveProducts())->json('data.id');

        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);
        $this->getJson('/api/v1/imports/'.$id)->assertForbidden();
        $this->upload($this->fiveProducts())->assertForbidden();

        $this->actingAsMember(Tenant::factory()->create());
        $this->getJson('/api/v1/imports/'.$id)->assertNotFound();
    }

    #[Test]
    public function an_import_whose_file_has_gone_fails_with_a_reason_instead_of_hanging(): void
    {
        Queue::fake();
        $id = $this->upload($this->fiveProducts())->json('data.id');
        Storage::disk('local')->deleteDirectory('imports');

        $this->asTenant(fn () => $this->app->make(RunProductImport::class)($id));

        $this->getJson('/api/v1/imports/'.$id)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure', 'The uploaded file is no longer available.');
    }
}
