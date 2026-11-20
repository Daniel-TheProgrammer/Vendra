<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Feature;

use App\Modules\Tenancy\Infrastructure\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('database')]
final class TenantCommandsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function tenant_create_persists_a_tenant_and_reports_its_id(): void
    {
        $this->runArtisan('tenant:create', ['name' => 'Acme Corp', '--plan' => 'pro'])
            ->expectsOutputToContain('acme-corp')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-corp', 'plan' => 'pro']);
    }

    #[Test]
    public function tenant_create_rejects_an_unknown_plan(): void
    {
        $this->runArtisan('tenant:create', ['name' => 'Acme', '--plan' => 'platinum'])
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
    }

    #[Test]
    public function tenant_create_reports_a_taken_slug_as_a_failure(): void
    {
        Tenant::factory()->create(['slug' => 'acme']);

        $this->runArtisan('tenant:create', ['name' => 'Acme', '--slug' => 'acme'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    #[Test]
    public function tenant_list_shows_every_tenant(): void
    {
        Tenant::factory()->create(['slug' => 'acme']);
        Tenant::factory()->create(['slug' => 'globex']);

        $this->runArtisan('tenant:list')
            ->expectsOutputToContain('acme')
            ->expectsOutputToContain('globex')
            ->assertSuccessful();
    }

    #[Test]
    public function tenant_run_executes_a_command_inside_the_tenant_context(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'acme']);

        Artisan::command('tenant:whoami', function (): void {
            /** @var ClosureCommand $this */
            $this->line('tenant='.app(TenantContext::class)->id());
        });

        $this->runArtisan('tenant:run', ['tenant' => 'acme', 'artisan-command' => 'tenant:whoami'])
            ->expectsOutputToContain('tenant='.$tenant->id)
            ->assertSuccessful();

        self::assertFalse($this->app->make(TenantContext::class)->has());
    }

    #[Test]
    public function tenant_run_fails_for_an_unknown_tenant(): void
    {
        $this->runArtisan('tenant:run', ['tenant' => 'nope', 'artisan-command' => 'tenant:list'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }
}
