<?php

use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\SellerOrderController;
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
 * The seller SPA (resources/js/seller/*) otherwise talks to Supabase
 * directly for profile/product data; these routes exist because order
 * status changes are a privileged, auditable action best enforced
 * server-side rather than trusted to client-side RLS alone.
 */
Route::middleware(['supabase.auth', 'seller'])->prefix('api/seller')->name('api.seller.')->group(function () {
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('products.store');
    Route::get('/orders', [SellerOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [SellerOrderController::class, 'show'])->name('orders.show');
    Route::put('/orders/{id}/status', [SellerOrderController::class, 'updateStatus'])->name('orders.update-status');
});
