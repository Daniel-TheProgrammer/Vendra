<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Tenancy;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Modules\Tenancy\Tests\Support\TenantNote;
use App\Shared\Tenancy\Rls\TenantRls;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

/**
 * Proves the database enforces tenant isolation on its own, without the
 * Eloquent scope. Every query here goes through the query builder on purpose.
 */
#[Group('database')]
final class RowLevelSecurityTest extends TestCase
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
        TenantRls::enable('tenant_notes');

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
        $this->context = $this->app->make(TenantContext::class);

        $this->context->set($this->tenantA->id);
        TenantNote::query()->create(['body' => 'from A']);
        $this->context->set($this->tenantB->id);
        TenantNote::query()->create(['body' => 'from B']);
        $this->context->clear();
    }

    protected function tearDown(): void
    {
        $this->context->clear();

        parent::tearDown();
    }

    #[Test]
    public function the_session_setting_follows_the_tenant_context(): void
    {
        self::assertSame('', $this->currentSetting());

        $this->context->set($this->tenantA->id);
        self::assertSame($this->tenantA->id, $this->currentSetting());

        $this->context->clear();
        self::assertSame('', $this->currentSetting());
    }

    #[Test]
    public function the_application_role_cannot_bypass_row_level_security(): void
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

        self::assertNotNull($role);
        self::assertFalse($role->rolsuper, 'the test suite must not run as a superuser');
        self::assertFalse($role->rolbypassrls, 'the test suite must not run as a BYPASSRLS role');
    }

    #[Test]
    public function a_raw_query_only_sees_the_current_tenant(): void
    {
        $this->context->set($this->tenantA->id);
        self::assertSame(['from A'], DB::table('tenant_notes')->pluck('body')->all());

        $this->context->set($this->tenantB->id);
        self::assertSame(['from B'], DB::table('tenant_notes')->pluck('body')->all());
    }

    #[Test]
    public function with_no_tenant_a_raw_query_sees_nothing(): void
    {
        self::assertSame(0, DB::table('tenant_notes')->count());
    }

    #[Test]
    public function the_eloquent_escape_hatch_does_not_bypass_the_database_policy(): void
    {
        $this->context->set($this->tenantA->id);

        self::assertSame(1, TenantNote::withoutTenancy()->count());
    }

    #[Test]
    public function writing_a_row_for_another_tenant_is_rejected(): void
    {
        $this->context->set($this->tenantA->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security policy');

        // Nested transaction = savepoint: the rejection must not poison the
        // outer transaction RefreshDatabase wraps around the test.
        DB::transaction(fn () => DB::table('tenant_notes')->insert([
            'id' => '0193a7c0-0000-7000-8000-0000000000ff',
            'tenant_id' => $this->tenantB->id,
            'body' => 'smuggled',
        ]));
    }

    #[Test]
    public function updates_cannot_move_a_row_to_another_tenant(): void
    {
        $this->context->set($this->tenantA->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security policy');

        DB::transaction(fn () => DB::table('tenant_notes')->update(['tenant_id' => $this->tenantB->id]));
    }

    #[Test]
    public function every_table_with_a_tenant_id_column_is_protected(): void
    {
        $unprotected = DB::select(<<<'SQL'
            SELECT c.relname
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE c.relkind = 'r'
              AND n.nspname = 'public'
              AND COALESCE(obj_description(c.oid, 'pg_class'), '') NOT LIKE 'vendra:platform-table:%'
              AND NOT (
                    c.relrowsecurity
                AND c.relforcerowsecurity
                AND EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid)
              )
            ORDER BY c.relname
        SQL);

        self::assertSame([], array_map(static fn (stdClass $row): string => (string) $row->relname, $unprotected));
    }

    private function currentSetting(): string
    {
        $row = DB::selectOne(sprintf("SELECT current_setting('%s', true) AS value", TenantRls::SETTING));

        return $row === null ? '' : (string) $row->value;
    }
}
