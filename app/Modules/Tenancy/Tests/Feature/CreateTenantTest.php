<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Feature;

use App\Modules\Tenancy\Application\Actions\CreateTenant;
use App\Modules\Tenancy\Application\Commands\CreateTenantCommand;
use App\Modules\Tenancy\Domain\Events\TenantCreated;
use App\Modules\Tenancy\Domain\InvalidTenantSlug;
use App\Modules\Tenancy\Domain\TenantPlan;
use App\Modules\Tenancy\Domain\TenantSlugTaken;
use App\Modules\Tenancy\Domain\TenantStatus;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_an_active_tenant_with_a_slug_derived_from_the_name(): void
    {
        Event::fake([TenantCreated::class]);
        $this->freezeClock(CarbonImmutable::parse('2026-03-01T12:00:00Z'));

        $tenant = $this->app->make(CreateTenant::class)(new CreateTenantCommand(name: 'Acme Corp', plan: TenantPlan::Pro));

        self::assertSame('acme-corp', $tenant->slug);
        self::assertSame(TenantPlan::Pro, $tenant->plan);
        self::assertSame(TenantStatus::Active, $tenant->status);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'slug' => 'acme-corp']);

        Event::assertDispatched(
            TenantCreated::class,
            fn (TenantCreated $e): bool => $e->tenantId === $tenant->id
                && $e->slug === 'acme-corp'
                && $e->occurredAt->toIso8601String() === '2026-03-01T12:00:00+00:00',
        );
    }

    #[Test]
    public function an_explicit_slug_is_honoured(): void
    {
        $tenant = $this->app->make(CreateTenant::class)(new CreateTenantCommand(name: 'Acme Corp', slug: 'acme'));

        self::assertSame('acme', $tenant->slug);
    }

    #[Test]
    public function a_duplicate_slug_is_a_conflict(): void
    {
        Tenant::factory()->create(['slug' => 'acme']);

        $this->expectException(TenantSlugTaken::class);

        $this->app->make(CreateTenant::class)(new CreateTenantCommand(name: 'Another', slug: 'acme'));
    }

    #[Test]
    public function a_malformed_slug_is_rejected_before_touching_the_database(): void
    {
        $this->expectException(InvalidTenantSlug::class);

        $this->app->make(CreateTenant::class)(new CreateTenantCommand(name: 'Acme', slug: 'Not A Slug!'));
    }
}
