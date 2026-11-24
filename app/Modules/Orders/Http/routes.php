<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
 * People and API keys alike: orders.view maps to the orders:read scope,
 * orders.manage to orders:write. Every write requires an Idempotency-Key
 * (ADR 0007): placing an order twice is the costliest duplicate there is.
 */
Route::middleware(['auth:sanctum,api-key', 'tenant'])->group(function (): void {
    Route::middleware('permission:orders.view')->group(function (): void {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->whereUuid('order')->name('orders.show');
        Route::get('orders/{order}/events', [OrderController::class, 'events'])->whereUuid('order')->name('orders.events');
    });

    Route::middleware(['permission:orders.manage', 'idempotent'])->group(function (): void {
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order')->name('orders.cancel');
        Route::post('orders/{order}/fulfil', [OrderController::class, 'fulfil'])->whereUuid('order')->name('orders.fulfil');
        Route::put('orders/{order}/shipping-address', [OrderController::class, 'updateShippingAddress'])->whereUuid('order')->name('orders.shipping-address');
    });
});
