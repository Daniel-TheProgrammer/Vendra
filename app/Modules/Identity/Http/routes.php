<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ApiKeyController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\TokenController;
use Illuminate\Support\Facades\Route;

/*
 * Public: everything a client needs before it holds a token.
 */
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:auth')->name('password.forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:auth')->name('password.reset');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:auth'])
        ->whereUuid('id')
        ->name('verification.verify');
});

/*
 * Authenticated: the token decides the user and the tenant.
 */
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('me', MeController::class)->name('me');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('switch-tenant', [AuthController::class, 'switchTenant'])->name('switch-tenant');
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:auth')
            ->name('verification.send');

        Route::get('tokens', [TokenController::class, 'index'])->name('tokens.index');
        Route::post('tokens', [TokenController::class, 'store'])->name('tokens.store');
        Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->whereUuid('token')->name('tokens.destroy');
    });

    /*
     * API keys are managed by people only: these routes accept personal
     * access tokens, never another API key.
     */
    Route::prefix('api-keys')->name('api-keys.')->group(function (): void {
        Route::get('/', [ApiKeyController::class, 'index'])->name('index');
        Route::post('/', [ApiKeyController::class, 'store'])->name('store');
        Route::post('{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->whereUuid('apiKey')->name('rotate');
        Route::delete('{apiKey}', [ApiKeyController::class, 'destroy'])->whereUuid('apiKey')->name('destroy');
    });
});
