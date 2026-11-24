<?php

declare(strict_types=1);

use App\Modules\Ledger\Http\Controllers\JournalEntryController;
use App\Modules\Ledger\Http\Controllers\LedgerAccountController;
use App\Modules\Ledger\Http\Controllers\TrialBalanceController;
use Illuminate\Support\Facades\Route;

/*
 * Reading is ledger.view (every role; the ledger:read scope). Posting a
 * manual entry or reversing one is ledger.post: owners only, never an API
 * key (docs/permissions.md).
 */
Route::middleware(['auth:sanctum,api-key', 'tenant'])->prefix('ledger')->name('ledger.')->group(function (): void {
    Route::middleware('permission:ledger.view')->group(function (): void {
        Route::get('accounts', [LedgerAccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{account}/balance', [LedgerAccountController::class, 'balance'])->whereUuid('account')->name('accounts.balance');
        Route::get('entries', [JournalEntryController::class, 'index'])->name('entries.index');
        Route::get('entries/export', [JournalEntryController::class, 'export'])->name('entries.export');
        Route::get('entries/{entry}', [JournalEntryController::class, 'show'])->whereUuid('entry')->name('entries.show');
        Route::get('trial-balance', TrialBalanceController::class)->name('trial-balance');
    });

    Route::middleware(['permission:ledger.post', 'idempotent'])->group(function (): void {
        Route::post('entries', [JournalEntryController::class, 'store'])->name('entries.store');
        Route::post('entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereUuid('entry')->name('entries.reverse');
    });
});
