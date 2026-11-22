<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\ShowCurrentTenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum,api-key', 'tenant'])->group(function (): void {
    Route::get('tenant', ShowCurrentTenantController::class)->middleware('permission:tenant.view')->name('tenant.show');
});
