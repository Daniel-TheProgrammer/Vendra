<?php

declare(strict_types=1);

namespace App\Shared;

use App\Shared\Application\Clock\Clock;
use App\Shared\Application\Clock\SystemClock;
use App\Shared\Application\Transaction\DatabaseTransactionRunner;
use App\Shared\Application\Transaction\TransactionRunner;
use App\Shared\Infrastructure\Persistence\BlueprintMacros;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(TransactionRunner::class, DatabaseTransactionRunner::class);
        $this->app->singleton(TenantContext::class);

        Date::use(CarbonImmutable::class);
    }

    public function boot(): void
    {
        BlueprintMacros::register();
    }
}
