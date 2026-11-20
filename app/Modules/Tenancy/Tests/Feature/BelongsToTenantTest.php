<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Feature;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Tests\Support\TenantNote;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantNotResolved;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('body');
            $table->timestampsTz();
        });

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        parent::tearDown();
    }

    #[Test]
    public function tenant_id_is_filled_from_the_context_on_create(): void
    {
        $this->context->set($this->tenantA->id);

        $note = TenantNote::query()->create(['body' => 'hello']);

        self::assertSame($this->tenantA->id, $note->tenant_id);
    }

    #[Test]
    public function creating_without_a_tenant_is_refused(): void
    {
        $this->expectException(TenantNotResolved::class);

        TenantNote::query()->create(['body' => 'orphan']);
    }

    #[Test]
    public function rows_of_another_tenant_are_invisible(): void
    {
        $this->context->set($this->tenantA->id);
        TenantNote::query()->create(['body' => 'from A']);

        $this->context->set($this->tenantB->id);
        TenantNote::query()->create(['body' => 'from B']);

        self::assertSame(1, TenantNote::query()->count());
        self::assertSame('from B', TenantNote::query()->firstOrFail()->body);

        $this->context->set($this->tenantA->id);
        self::assertSame('from A', TenantNote::query()->firstOrFail()->body);
    }

    #[Test]
    public function without_a_tenant_the_scope_matches_nothing(): void
    {
        $this->context->set($this->tenantA->id);
        TenantNote::query()->create(['body' => 'from A']);
        $this->context->clear();

        self::assertSame(0, TenantNote::query()->count());
        self::assertSame(1, DB::table('tenant_notes')->count(), 'the row exists; only the scope hides it');
    }

    #[Test]
    public function without_tenancy_is_the_explicit_cross_tenant_escape_hatch(): void
    {
        $this->context->set($this->tenantA->id);
        TenantNote::query()->create(['body' => 'from A']);
        $this->context->set($this->tenantB->id);
        TenantNote::query()->create(['body' => 'from B']);

        self::assertSame(2, TenantNote::withoutTenancy()->count());
    }
}
