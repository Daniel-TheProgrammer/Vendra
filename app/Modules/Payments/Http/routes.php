<?php

declare(strict_types=1);

use App\Modules\Payments\Http\Controllers\PaymentAccountController;
use App\Modules\Payments\Http\Controllers\PaymentIntentController;
use Illuminate\Support\Facades\Route;

/*
 * Payments are part of an order: whoever may see the order sees its
 * payments (orders.view). Moving money is payments.manage (payments:write).
 * Connecting the tenant's provider account decides where the money goes:
 * payments.configure, people only (docs/permissions.md).
 */
Route::middleware(['auth:sanctum,api-key', 'tenant'])->group(function (): void {
    Route::middleware('permission:orders.view')->group(function (): void {
        Route::get('payment-intents/{paymentIntent}', [PaymentIntentController::class, 'show'])->whereUuid('paymentIntent')->name('payment-intents.show');
    });

    Route::middleware(['permission:payments.manage', 'idempotent'])->group(function (): void {
        Route::post('orders/{order}/payment-intents', [PaymentIntentController::class, 'store'])->whereUuid('order')->name('payment-intents.store');
        Route::post('payment-intents/{paymentIntent}/capture', [PaymentIntentController::class, 'capture'])->whereUuid('paymentIntent')->name('payment-intents.capture');
    });

    Route::middleware('permission:payments.configure')->group(function (): void {
        Route::get('payments/account', [PaymentAccountController::class, 'show'])->name('payments.account.show');
        Route::put('payments/account', [PaymentAccountController::class, 'update'])->name('payments.account.update');
    });
});
