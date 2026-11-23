<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Catalog\Domain\Events\ProductArchived;
use App\Modules\Catalog\Domain\Events\ProductCreated;
use App\Modules\Catalog\Domain\Events\ProductUpdated;
use App\Modules\Catalog\Infrastructure\Models\Product;
use App\Modules\Identity\Domain\ApiScope;
use App\Modules\Identity\Domain\MembershipRole;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

#[Group('database')]
final class ProductApiTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Organic Cotton Tee',
            'description' => 'Heavyweight, garment dyed.',
            'status' => 'active',
            'variants' => [
                [
                    'sku' => 'TEE-RED-M',
                    'name' => 'Red / M',
                    'options' => ['colour' => 'red', 'size' => 'M'],
                    'prices' => [['currency' => 'USD', 'amount' => '19.99'], ['currency' => 'JPY', 'amount' => '2900']],
                ],
                ['sku' => 'TEE-RED-L', 'prices' => [['currency' => 'USD', 'amount' => '21']]],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    private function createProduct(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/products', $this->payload($overrides));
    }

    private function productCount(Tenant $tenant): int
    {
        return $this->app->make(TenantContext::class)->runAs($tenant->id, static fn (): int => Product::query()->count());
    }

    #[Test]
    public function staff_create_a_product_with_its_variants_and_prices(): void
    {
        Event::fake([ProductCreated::class]);
        $this->actingAsMember($this->tenant, MembershipRole::Staff);

        $response = $this->createProduct()
            ->assertCreated()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.variants.0.sku', 'TEE-RED-M')
            ->assertJsonPath('data.variants.0.options.size', 'M')
            ->assertJsonPath('data.variants.0.prices.0', ['currency' => 'JPY', 'amount' => '2900', 'amount_minor' => 2900])
            ->assertJsonPath('data.variants.0.prices.1', ['currency' => 'USD', 'amount' => '19.99', 'amount_minor' => 1999])
            ->assertJsonPath('data.variants.1.prices.0.amount', '21.00')
            ->assertJsonPath('data.variants.1.options', []);

        $id = $response->json('data.id');
        $response->assertHeader('Location', route('api.v1.products.show', ['product' => $id]));

        $this->getJson('/api/v1/products/'.$id)->assertOk()->assertHeader('ETag', '"1"')->assertJsonCount(2, 'data.variants');

        Event::assertDispatched(ProductCreated::class, fn (ProductCreated $event): bool => $event->productId === $id && $event->tenantId === $this->tenant->id);
    }

    #[Test]
    public function new_products_default_to_draft(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::Staff);

        $payload = $this->payload();
        unset($payload['status']);

        $this->postJson('/api/v1/products', $payload)->assertCreated()->assertJsonPath('data.status', 'draft');
    }

    #[Test]
    public function read_only_members_browse_but_cannot_change_the_catalog(): void
    {
        $this->actingAsMember($this->tenant, MembershipRole::ReadOnly);

        $this->getJson('/api/v1/products')->assertOk();
        $this->createProduct()->assertForbidden()->assertJsonPath('type', 'urn:problem-type:action-not-permitted');
    }

    #[Test]
    public function api_keys_need_the_matching_catalog_scope(): void
    {
        $this->actingAsApiKey($this->tenant, [ApiScope::CatalogRead]);
        $this->getJson('/api/v1/products')->assertOk();
        $this->createProduct()->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::TenantRead]);
        $this->getJson('/api/v1/products')->assertForbidden();

        $this->actingAsApiKey($this->tenant, [ApiScope::CatalogWrite]);
        $this->createProduct()->assertCreated();
    }

    #[Test]
    public function the_payload_is_validated_before_anything_is_written(): void
    {
        $this->actingAsMember($this->tenant);

        $this->createProduct(['variants' => []])->assertUnprocessable()->assertJsonPath('errors.variants.0', fn (string $m): bool => $m !== '');

        $this->createProduct(['variants' => [['sku' => 'A-1'], ['sku' => 'A-1']]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['variants.0.sku']]);

        $this->createProduct(['variants' => [['sku' => 'bad sku!']]])->assertUnprocessable()->assertJsonStructure(['errors' => ['variants.0.sku']]);

        $this->createProduct(['variants' => [['sku' => 'A-1', 'prices' => [['currency' => 'JPY', 'amount' => '10.5']]]]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['variants.0.prices.0.amount' => ['JPY amounts have at most 0 decimal places.']]);

        $this->createProduct(['variants' => [['sku' => 'A-1', 'prices' => [['currency' => 'USD', 'amount' => '1'], ['currency' => 'USD', 'amount' => '2']]]]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['variants.0.prices.1.currency']]);

        $this->createProduct(['variants' => [['sku' => 'A-1', 'prices' => [['currency' => 'USD', 'amount' => 19.99]]]]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['variants.0.prices.0.amount']]);

        $this->createProduct(['variants' => [['sku' => 'A-1', 'options' => ['red', 'M']]]])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['variants.0.options']]);

        $this->createProduct(['status' => 'archived'])->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);

        self::assertSame(0, $this->productCount($this->tenant));
    }

    #[Test]
    public function a_taken_sku_is_a_conflict_and_nothing_is_created(): void
    {
        $this->actingAsMember($this->tenant);
        $this->createProduct()->assertCreated();

        $this->createProduct(['variants' => [['sku' => 'NEW-1'], ['sku' => 'TEE-RED-L']]])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:sku-taken')
            ->assertJsonPath('sku', 'TEE-RED-L');

        self::assertSame(1, $this->productCount($this->tenant));
    }

    #[Test]
    public function skus_are_unique_per_tenant_not_globally(): void
    {
        $this->actingAsMember($this->tenant);
        $this->createProduct()->assertCreated();

        $this->actingAsMember(Tenant::factory()->create());
        $this->createProduct()->assertCreated();
    }

    #[Test]
    public function another_tenants_product_does_not_exist_for_you(): void
    {
        $this->actingAsMember($this->tenant);
        $id = $this->createProduct()->json('data.id');

        $this->actingAsMember(Tenant::factory()->create());

        $this->getJson('/api/v1/products/'.$id)->assertNotFound();
        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Hijacked'])->assertNotFound();
        $this->postJson('/api/v1/products/'.$id.'/archive')->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function updates_are_partial_and_guarded_by_if_match(): void
    {
        Event::fake([ProductUpdated::class]);
        $this->actingAsMember($this->tenant);
        $id = $this->createProduct()->json('data.id');

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Organic Tee', 'description' => null], ['If-Match' => '"1"'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.name', 'Organic Tee')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.status', 'active');

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Lost update'], ['If-Match' => '"1"'])
            ->assertStatus(412)
            ->assertJsonPath('type', 'urn:problem-type:precondition-failed')
            ->assertJsonPath('current_version', 2);

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'X'], ['If-Match' => 'garbage'])->assertBadRequest();

        Event::assertDispatchedTimes(ProductUpdated::class, 1);
    }

    #[Test]
    public function an_update_that_changes_nothing_keeps_the_version(): void
    {
        $this->actingAsMember($this->tenant);
        $id = $this->createProduct()->json('data.id');

        $this->patchJson('/api/v1/products/'.$id, ['name' => 'Organic Cotton Tee', 'status' => 'active'])
            ->assertOk()
            ->assertHeader('ETag', '"1"');
    }

    #[Test]
    public function archived_products_are_read_only_and_archiving_is_idempotent(): void
    {
        Event::fake([ProductArchived::class]);
        $this->actingAsMember($this->tenant);
        $product = $this->createProduct()->json('data');

        $this->postJson('/api/v1/products/'.$product['id'].'/archive', [], ['If-Match' => '"1"'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.archived_at', fn (?string $at): bool => $at !== null);

        // A retry with the original precondition is still a success, not a 412.
        $this->postJson('/api/v1/products/'.$product['id'].'/archive', [], ['If-Match' => '"1"'])->assertOk();

        $this->patchJson('/api/v1/products/'.$product['id'], ['name' => 'Revived'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:product-archived');
        $this->putJson('/api/v1/variants/'.$product['variants'][0]['id'].'/prices/USD', ['amount' => '1.00'])->assertConflict();
        $this->postJson('/api/v1/products/'.$product['id'].'/variants', ['sku' => 'LATE-1'])->assertConflict();

        Event::assertDispatchedTimes(ProductArchived::class, 1);
    }

    #[Test]
    public function variants_and_prices_are_managed_under_the_product_version(): void
    {
        $this->actingAsMember($this->tenant);
        $product = $this->createProduct()->json('data');
        $variantId = $product['variants'][1]['id'];

        $this->postJson('/api/v1/products/'.$product['id'].'/variants', [
            'sku' => 'TEE-RED-XL',
            'options' => ['colour' => 'red', 'size' => 'XL'],
            'prices' => [['currency' => 'EUR', 'amount' => '22.50']],
        ])->assertCreated()->assertHeader('ETag', '"2"')->assertJsonCount(3, 'data.variants');

        $this->postJson('/api/v1/products/'.$product['id'].'/variants', ['sku' => 'TEE-RED-M'])
            ->assertConflict()
            ->assertJsonPath('type', 'urn:problem-type:sku-taken');

        $this->putJson('/api/v1/variants/'.$variantId.'/prices/GBP', ['amount' => '17.25'], ['If-Match' => '"2"'])
            ->assertOk()
            ->assertHeader('ETag', '"3"')
            ->assertJsonPath('data.variants.1.prices', [
                ['currency' => 'GBP', 'amount' => '17.25', 'amount_minor' => 1725],
                ['currency' => 'USD', 'amount' => '21.00', 'amount_minor' => 2100],
            ]);

        // Same price again: nothing changed, the version stays.
        $this->putJson('/api/v1/variants/'.$variantId.'/prices/GBP', ['amount' => '17.25'])->assertOk()->assertHeader('ETag', '"3"');

        $this->putJson('/api/v1/variants/'.$variantId.'/prices/KWD', ['amount' => '1.2345'])->assertUnprocessable();
        $this->putJson('/api/v1/variants/'.$variantId.'/prices/XXX', ['amount' => '1'])->assertNotFound();

        $this->deleteJson('/api/v1/variants/'.$variantId.'/prices/GBP')->assertOk()->assertHeader('ETag', '"4"')->assertJsonCount(1, 'data.variants.1.prices');
        $this->deleteJson('/api/v1/variants/'.$variantId.'/prices/GBP')->assertNotFound();
    }

    #[Test]
    public function the_list_is_cursor_paginated_and_filterable(): void
    {
        $this->actingAsMember($this->tenant);

        foreach (['Linen Shirt', 'Wool Socks', 'Linen Trousers'] as $index => $name) {
            $this->createProduct([
                'name' => $name,
                'status' => $index === 1 ? 'draft' : 'active',
                'variants' => [['sku' => 'SKU-'.$index]],
            ])->assertCreated();
        }

        $first = $this->getJson('/api/v1/products?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        self::assertSame(['Linen Trousers', 'Wool Socks'], array_column($first->json('data'), 'name'));

        $cursor = $first->json('meta.next_cursor');
        self::assertIsString($cursor);

        $second = $this->getJson('/api/v1/products?per_page=2&cursor='.urlencode($cursor))->assertOk();
        self::assertSame(['Linen Shirt'], array_column($second->json('data'), 'name'));
        self::assertNull($second->json('meta.next_cursor'));

        self::assertSame(['Linen Trousers', 'Linen Shirt'], array_column($this->getJson('/api/v1/products?search=linen')->json('data'), 'name'));
        self::assertSame(['Wool Socks'], array_column($this->getJson('/api/v1/products?status=draft')->json('data'), 'name'));
        self::assertSame(['Linen Shirt'], array_column($this->getJson('/api/v1/products?sku=SKU-0')->json('data'), 'name'));
        self::assertSame([], $this->getJson('/api/v1/products?search=%25')->json('data'));

        $this->getJson('/api/v1/products?per_page=500')->assertUnprocessable();
    }
}
