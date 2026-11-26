<?php

declare(strict_types=1);

namespace App\Shared;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Clock\SystemClock;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Application\Transaction\DatabaseTransactionRunner;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Infrastructure\Audit\DatabaseAuditTrail;
use App\Shared\Infrastructure\Http\Idempotency\PruneIdempotencyKeys;
use App\Shared\Infrastructure\Outbox\EventRegistry;
use App\Shared\Infrastructure\Outbox\OutboxRecorder;
use App\Shared\Infrastructure\Outbox\OutboxRelay;
use App\Shared\Infrastructure\Outbox\PruneOutboxCommand;
use App\Shared\Infrastructure\Outbox\RelayOutboxCommand;
use App\Shared\Infrastructure\Persistence\BlueprintMacros;
use App\Shared\Tenancy\Rls\SyncTenantSessionVariable;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextChanged;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(TransactionRunner::class, DatabaseTransactionRunner::class);
        $this->app->singleton(AuditTrail::class, DatabaseAuditTrail::class);
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(SyncTenantSessionVariable::class);
        $this->app->singleton(EventRegistry::class);
        $this->app->singleton(EventRecorder::class, OutboxRecorder::class);
        $this->app->singleton(OutboxRelay::class);

        Date::use(CarbonImmutable::class);
    }

    public function boot(Dispatcher $events): void
    {
        BlueprintMacros::register();

        if ($this->app->runningInConsole()) {
            $this->commands([PruneIdempotencyKeys::class, RelayOutboxCommand::class, PruneOutboxCommand::class]);
        }

        $events->listen(TenantContextChanged::class, [SyncTenantSessionVariable::class, 'onTenantChanged']);
        $events->listen(ConnectionEstablished::class, [SyncTenantSessionVariable::class, 'onConnectionEstablished']);

        // Queue workers restore Context before running a job; mirror it to the database too.
        Context::hydrated(fn () => $this->app->make(SyncTenantSessionVariable::class)->sync());
    }
}
