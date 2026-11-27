<?php

declare(strict_types=1);

use App\Modules\Webhooks\Http\Controllers\DeliveryController;
use App\Modules\Webhooks\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
 * Outbound webhooks: webhooks.manage (owners and admins; the
 * webhooks:manage scope). Inbound provider webhooks (POST
 * /webhooks/stripe) belong to Payments.
 */
Route::middleware(['auth:sanctum,api-key', 'tenant', 'permission:webhooks.manage'])->group(function (): void {
    // The tenant's event log: what its subscriptions are fed from.
    Route::get('events', [DeliveryController::class, 'events'])->name('events.index');

    Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
        Route::get('event-types', [SubscriptionController::class, 'eventTypes'])->name('event-types');
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show'])->whereUuid('subscription')->name('subscriptions.show');
        Route::get('subscriptions/{subscription}/secret', [SubscriptionController::class, 'secret'])->whereUuid('subscription')->name('subscriptions.secret');
        Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])->whereUuid('delivery')->name('deliveries.show');

        // A test ping is safe to repeat, and its request must not sit in a
        // transaction while the receiver answers: no idempotency layer.
        Route::post('subscriptions/{subscription}/test', [DeliveryController::class, 'test'])->whereUuid('subscription')->name('subscriptions.test');

        Route::middleware('idempotent')->group(function (): void {
            Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
            Route::patch('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->whereUuid('subscription')->name('subscriptions.update');
            Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->whereUuid('subscription')->name('subscriptions.destroy');
            Route::post('subscriptions/{subscription}/rotate-secret', [SubscriptionController::class, 'rotateSecret'])->whereUuid('subscription')->name('subscriptions.rotate-secret');
            Route::post('deliveries/{delivery}/redeliver', [DeliveryController::class, 'redeliver'])->whereUuid('delivery')->name('deliveries.redeliver');
        });
    });
});
