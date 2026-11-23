<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Tests\Feature;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IssuesApiKeys;
use Tests\TestCase;

/**
 * What a client syncing or browsing the catalog relies on: stable paging,
 * incremental sync, trimmed payloads and SKU lookups that ignore case.
 */
#[Group('database')]
final class ProductListingTest extends TestCase
{
    use IssuesApiKeys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsMember(Tenant::factory()->create());
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function create(string $name, string $sku): TestResponse
    {
        return $this->postJson('/api/v1/products', [
            'name' => $name,
            'status' => 'active',
            'variants' => [['sku' => $sku, 'prices' => [['currency' => 'USD', 'amount' => '10']]]],
        ])->assertCreated();
    }

    /**
     * @return list<string>
     */
    private function names(string $query): array
    {
        /** @var list<string> */
        return array_column($this->getJson('/api/v1/products'.$query)->assertOk()->json('data'), 'name');
    }

    #[Test]
    public function paging_neither_skips_nor_repeats_when_products_are_added_mid_iteration(): void
    {
        foreach (range(1, 5) as $n) {
            $this->create('Original '.$n, 'ORIG-'.$n);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->getJson('/api/v1/products?per_page=2'.($cursor !== null ? '&cursor='.urlencode($cursor) : ''))->assertOk();
            array_push($seen, ...array_column($page->json('data'), 'name'));

            // Other writers keep adding products while this client pages.
            $this->create('Added during page '.++$pages, 'ADDED-'.$pages);

            $cursor = $page->json('meta.next_cursor');
        } while (is_string($cursor));

        // New products sort before the cursor, so the walk ends exactly on the
        // set that existed when it began: no offset drift, no duplicates.
        self::assertSame(['Original 5', 'Original 4', 'Original 3', 'Original 2', 'Original 1'], $seen);
        self::assertSame(3, $pages);
    }

    #[Test]
    public function updated_since_returns_only_products_changed_from_that_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20T09:00:00Z'));
        $stale = $this->create('Untouched', 'OLD-1')->json('data.id');
        $edited = $this->create('Edited later', 'OLD-2')->json('data.id');

        $this->travelTo(CarbonImmutable::parse('2026-11-22T09:00:00Z'));
        $this->patchJson('/api/v1/products/'.$edited, ['description' => 'Now with pockets.'])->assertOk();
        $this->create('Created later', 'NEW-1');

        self::assertSame(['Created later', 'Edited later'], $this->names('?updated_since=2026-11-21T00:00:00Z'));
        // Offsets are honoured: 2026-11-22T10:00+02:00 is 08:00Z, before both changes.
        self::assertSame(['Created later', 'Edited later'], $this->names('?updated_since='.urlencode('2026-11-22T10:00:00+02:00')));
        self::assertSame([], $this->names('?updated_since=2026-11-22T09:00:00.000001Z'));
        self::assertContains('Untouched', $this->names('?updated_since=2026-11-20T09:00:00Z'));
        self::assertIsString($stale);

        foreach (['yesterday', '2026-11-21', '2026-11-21T00:00:00'] as $ambiguous) {
            $this->getJson('/api/v1/products?updated_since='.$ambiguous)
                ->assertUnprocessable()
                ->assertJsonStructure(['errors' => ['updated_since']]);
        }
    }

    #[Test]
    public function sparse_fieldsets_trim_the_representation_but_always_keep_the_id(): void
    {
        $id = $this->create('Linen Shirt', 'LIN-1')->json('data.id');

        $list = $this->getJson('/api/v1/products?fields=name,status')->assertOk();
        self::assertSame([['id' => $id, 'name' => 'Linen Shirt', 'status' => 'active']], $list->json('data'));
        self::assertArrayHasKey('next_cursor', $list->json('meta'));

        $this->getJson('/api/v1/products/'.$id.'?fields=variants')
            ->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.variants.0.sku', 'LIN-1')
            ->assertJsonMissingPath('data.name');

        $this->getJson('/api/v1/products?fields=name,cost_price')->assertUnprocessable()->assertJsonStructure(['errors' => ['fields']]);
        $this->getJson('/api/v1/products?fields=')->assertUnprocessable();
        $this->getJson('/api/v1/products?fields[]=name')->assertUnprocessable();
    }

    #[Test]
    public function skus_are_case_insensitive_identifiers_stored_in_one_canonical_form(): void
    {
        $this->create('Red Tee', ' tee-red-m ')->assertJsonPath('data.variants.0.sku', 'TEE-RED-M');

        $this->postJson('/api/v1/products', [
            'name' => 'Duplicate in another case',
            'variants' => [['sku' => 'Tee-Red-M']],
        ])->assertConflict()->assertJsonPath('sku', 'TEE-RED-M');

        $this->postJson('/api/v1/products', [
            'name' => 'Same SKU twice in one payload',
            'variants' => [['sku' => 'MUG-1'], ['sku' => 'mug-1']],
        ])->assertUnprocessable()->assertJsonStructure(['errors' => ['variants.1.sku']]);

        self::assertSame(['Red Tee'], $this->names('?sku=tee-red-m'));
    }
}
