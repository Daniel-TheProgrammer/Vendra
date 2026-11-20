<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Tests\Unit;

use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextChanged;
use App\Shared\Tenancy\TenantNotResolved;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class TenantContextTest extends TestCase
{
    private const TENANT_A = '0193a7c0-0000-7000-8000-00000000000a';

    private const TENANT_B = '0193a7c0-0000-7000-8000-00000000000b';

    #[Test]
    public function it_starts_empty_and_fails_loudly_when_read(): void
    {
        $context = $this->app->make(TenantContext::class);

        self::assertFalse($context->has());
        self::assertNull($context->idOrNull());

        $this->expectException(TenantNotResolved::class);
        $context->id();
    }

    #[Test]
    public function it_sets_and_clears_the_tenant(): void
    {
        $context = $this->app->make(TenantContext::class);

        $context->set(self::TENANT_A);
        self::assertSame(self::TENANT_A, $context->id());
        self::assertSame(self::TENANT_A, Context::get(TenantContext::KEY));

        $context->clear();
        self::assertFalse($context->has());
        self::assertNull(Context::get(TenantContext::KEY));
    }

    #[Test]
    public function run_as_restores_the_previous_tenant(): void
    {
        $context = $this->app->make(TenantContext::class);
        $context->set(self::TENANT_A);

        $seen = $context->runAs(self::TENANT_B, fn (): ?string => $context->idOrNull());

        self::assertSame(self::TENANT_B, $seen);
        self::assertSame(self::TENANT_A, $context->id());
    }

    #[Test]
    public function run_as_clears_when_there_was_no_previous_tenant(): void
    {
        $context = $this->app->make(TenantContext::class);

        try {
            $context->runAs(self::TENANT_A, static fn (): never => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        self::assertFalse($context->has());
    }

    #[Test]
    public function changes_are_announced(): void
    {
        Event::fake([TenantContextChanged::class]);
        $context = $this->app->make(TenantContext::class);

        $context->set(self::TENANT_A);
        $context->clear();

        Event::assertDispatched(TenantContextChanged::class, fn (TenantContextChanged $e): bool => $e->tenantId === self::TENANT_A);
        Event::assertDispatched(TenantContextChanged::class, fn (TenantContextChanged $e): bool => $e->tenantId === null);
    }

    #[Test]
    public function the_tenant_survives_the_context_round_trip_used_by_queued_jobs(): void
    {
        $context = $this->app->make(TenantContext::class);
        $context->set(self::TENANT_A);

        $payload = Context::dehydrate();
        Context::flush();
        self::assertFalse($context->has());

        Context::hydrate($payload);

        self::assertSame(self::TENANT_A, $context->id());
    }
}
