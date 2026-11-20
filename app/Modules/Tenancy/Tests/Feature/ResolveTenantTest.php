<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_current_tenant_is_resolved_from_the_header_by_slug_or_id(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme Corporation']);

        $this->getJson('/api/v1/tenant', [ResolveTenant::HEADER => 'acme'])
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('data.name', 'Acme Corporation')
            ->assertJsonPath('data.plan', 'free')
            ->assertJsonPath('data.status', 'active');

        $this->getJson('/api/v1/tenant', [ResolveTenant::HEADER => $tenant->id])
            ->assertOk()
            ->assertJsonPath('data.slug', 'acme');
    }

    #[Test]
    public function requests_without_a_resolvable_tenant_are_unauthenticated(): void
    {
        Tenant::factory()->create(['slug' => 'acme']);

        $this->getJson('/api/v1/tenant')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'urn:problem-type:unauthenticated');

        $this->getJson('/api/v1/tenant', [ResolveTenant::HEADER => 'nope'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_tenant_is_refused(): void
    {
        Tenant::factory()->suspended()->create(['slug' => 'acme']);

        $this->getJson('/api/v1/tenant', [ResolveTenant::HEADER => 'acme'])
            ->assertForbidden()
            ->assertJsonPath('type', 'urn:problem-type:tenant-suspended');
    }

    #[Test]
    public function the_context_is_cleared_after_the_request(): void
    {
        Tenant::factory()->create(['slug' => 'acme']);

        $this->getJson('/api/v1/tenant', [ResolveTenant::HEADER => 'acme'])->assertOk();

        self::assertFalse($this->app->make(TenantContext::class)->has());
    }
}
