<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Module;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires one module under app/Modules/<Name> into the framework.
 *
 * By convention a module may provide:
 *   Database/Migrations/   loaded automatically
 *   Http/routes.php        registered under the versioned API prefix with the "api" group
 *   moduleCommands()       Artisan commands to register
 *   modulePolicies()       model => policy map
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected const API_PREFIX = 'api/v1';

    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $base = app_path('Modules/'.$this->moduleName());

        if (is_dir($base.'/Database/Migrations')) {
            $this->loadMigrationsFrom($base.'/Database/Migrations');
        }

        if (is_file($base.'/Http/routes.php')) {
            Route::middleware('api')
                ->prefix(static::API_PREFIX)
                ->name('api.v1.')
                ->group($base.'/Http/routes.php');
        }

        foreach ($this->modulePolicies() as $model => $policy) {
            Gate::policy($model, $policy);
        }

        if ($this->app->runningInConsole()) {
            $this->commands($this->moduleCommands());
        }
    }

    /**
     * @return list<class-string>
     */
    protected function moduleCommands(): array
    {
        return [];
    }

    /**
     * @return array<class-string, class-string>
     */
    protected function modulePolicies(): array
    {
        return [];
    }
}
