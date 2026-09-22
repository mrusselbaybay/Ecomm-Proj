<?php

use App\Http\Controllers\Seller\CategoryConfigController;
use App\Http\Controllers\Seller\SellerInventoryController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerProductController;
use Illuminate\Support\Facades\Route;

/**
 * Included from routes/web.php.
 *
 * 'supabase.auth' -> verifies the Supabase access token sent as a Bearer
 *                    header and resolves the matching public.profiles row
 *                    onto the request (see AuthenticateSupabaseUser).
 * 'seller'        -> requires that resolved profile to be an active seller
 *                    (see EnsureUserIsSeller).
 *
 * Order status changes and product writes are privileged, auditable
 * actions enforced server-side rather than trusted to client-side RLS
 * alone (see SellerProductService for why product category/status is
 * never trusted from the client either).
 */
Route::middleware(['supabase.auth', 'seller'])->prefix('api/seller')->name('api.seller.')->group(function () {
    Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [SellerOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{id}/status', [SellerOrderController::class, 'updateStatus'])->name('orders.update-status');

    Route::get('/products', [SellerProductController::class, 'index'])->name('products.index');
    // Must stay ahead of the /products/{id} wildcard below, or "stock-trend"
    // gets swallowed as an {id} and 404s against SellerProductController.
    Route::get('/products/stock-trend', [SellerInventoryController::class, 'stockTrend'])->name('products.stock-trend');
    Route::get('/products/{id}', [SellerProductController::class, 'show'])->name('products.show');
    Route::post('/products', [SellerProductController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('products.store');
    Route::put('/products/{id}', [SellerProductController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('products.update');
    Route::delete('/products/{id}', [SellerProductController::class, 'destroy'])->name('products.destroy');

    // Inventory: manual stock adjustments + movement history. products.stock
    // / product_variants.stock only ever change via InventoryService, which
    // records an inventory_movements row for every change.
    Route::post('/products/{id}/stock-adjustments', [SellerInventoryController::class, 'adjust'])->name('products.stock.adjust');
    Route::get('/products/{id}/stock-movements', [SellerInventoryController::class, 'movements'])->name('products.stock.movements');

    Route::get('/category-config', [CategoryConfigController::class, 'show'])->name('category-config');
});
