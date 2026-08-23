<?php

use App\Http\Controllers\Seller\CategoryConfigController;
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
 * Product routes (added for variant support): category/status/price/
 * stock/variant data must be validated and enforced server-side (see
 * SellerProductService), which isn't possible if the SPA writes directly
 * to Supabase, so products moved onto this same pattern as orders below.
 */
Route::middleware(['supabase.auth', 'seller'])->prefix('api/seller')->name('api.seller.')->group(function () {
    Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [SellerOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{id}/status', [SellerOrderController::class, 'updateStatus'])->name('orders.update-status');

    Route::get('/products', [SellerProductController::class, 'index'])->name('products.index');
    Route::post('/products', [SellerProductController::class, 'store'])->name('products.store');
    Route::put('/products/{id}', [SellerProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [SellerProductController::class, 'destroy'])->name('products.destroy');

    Route::get('/category-config', [CategoryConfigController::class, 'show'])->name('category-config');
});