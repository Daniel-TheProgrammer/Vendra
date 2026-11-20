<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Lazy loading, silently discarded attributes and missing-attribute
        // access are bugs; surface them everywhere except production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // migrate:fresh and friends must never run against production data.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
