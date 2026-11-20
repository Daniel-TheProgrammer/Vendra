<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\ShowCurrentTenantController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->group(function (): void {
    Route::get('tenant', ShowCurrentTenantController::class)->name('tenant.show');
});
