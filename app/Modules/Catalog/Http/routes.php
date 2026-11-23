<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\StockController;
use App\Modules\Catalog\Http\Controllers\VariantController;
use App\Shared\Domain\Money\Currency;
use Illuminate\Support\Facades\Route;

/*
 * People and API keys alike: catalog.view maps to the catalog:read scope,
 * catalog.manage to catalog:write.
 */
Route::middleware(['auth:sanctum,api-key', 'tenant'])->group(function (): void {
    Route::middleware('permission:catalog.view')->group(function (): void {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->whereUuid('product')->name('products.show');
        Route::get('variants/{variant}/stock', [StockController::class, 'show'])->whereUuid('variant')->name('variants.stock.show');
        Route::get('variants/{variant}/stock-movements', [StockController::class, 'movements'])->whereUuid('variant')->name('variants.stock.movements');
    });

    Route::middleware('permission:catalog.manage')->group(function (): void {
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
        Route::patch('products/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('products.update');
        Route::post('products/{product}/archive', [ProductController::class, 'archive'])->whereUuid('product')->name('products.archive');
        Route::post('products/{product}/variants', [VariantController::class, 'store'])->whereUuid('product')->name('products.variants.store');

        Route::post('variants/{variant}/stock-adjustments', [StockController::class, 'adjust'])->whereUuid('variant')->name('variants.stock.adjust');

        Route::put('variants/{variant}/prices/{currency}', [VariantController::class, 'setPrice'])
            ->whereUuid('variant')
            ->whereIn('currency', array_column(Currency::cases(), 'value'))
            ->name('variants.prices.update');
        Route::delete('variants/{variant}/prices/{currency}', [VariantController::class, 'removePrice'])
            ->whereUuid('variant')
            ->whereIn('currency', array_column(Currency::cases(), 'value'))
            ->name('variants.prices.destroy');

        Route::post('variants/{variant}/scheduled-prices', [VariantController::class, 'schedulePrice'])
            ->whereUuid('variant')
            ->name('variants.scheduled-prices.store');
        Route::delete('variants/{variant}/scheduled-prices/{price}', [VariantController::class, 'cancelScheduledPrice'])
            ->whereUuid(['variant', 'price'])
            ->name('variants.scheduled-prices.destroy');
    });
});
