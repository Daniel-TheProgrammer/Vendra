<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ApiKeyController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\MemberController;
use App\Modules\Identity\Http\Controllers\PasswordController;
use App\Modules\Identity\Http\Controllers\RoleController;
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

Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:auth')->name('invitations.accept');

/*
 * Authenticated people: personal access tokens only. API keys never reach
 * these routes, whatever their scopes.
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

    Route::get('roles', RoleController::class)->middleware('permission:members.view')->name('roles.index');

    Route::prefix('members')->name('members.')->group(function (): void {
        Route::get('/', [MemberController::class, 'index'])->middleware('permission:members.view')->name('index');

        Route::middleware('permission:members.manage')->group(function (): void {
            Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
            Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereUuid('invitation')->name('invitations.destroy');
            Route::patch('{member}/role', [MemberController::class, 'updateRole'])->whereUuid('member')->name('role');
            Route::delete('{member}', [MemberController::class, 'destroy'])->whereUuid('member')->name('destroy');
        });
    });

    Route::prefix('api-keys')->name('api-keys.')->middleware('permission:api_keys.manage')->group(function (): void {
        Route::get('/', [ApiKeyController::class, 'index'])->name('index');
        Route::post('/', [ApiKeyController::class, 'store'])->name('store');
        Route::post('{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->whereUuid('apiKey')->name('rotate');
        Route::delete('{apiKey}', [ApiKeyController::class, 'destroy'])->whereUuid('apiKey')->name('destroy');
    });
});
