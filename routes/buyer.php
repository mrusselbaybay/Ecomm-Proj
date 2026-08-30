<?php

use App\Http\Controllers\Buyer\BuyerProfileController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\OrderController;
use Illuminate\Support\Facades\Route;

/**
 * Included from routes/web.php.
 *
 * 'supabase.auth' -> verifies the Supabase access token sent as a Bearer
 *                    header and resolves the matching public.profiles row
 *                    onto the request (see AuthenticateSupabaseUser).
 * 'buyer'         -> requires that resolved profile to be an active buyer
 *                    (see EnsureUserIsBuyer).
 *
 * Product browsing itself is public (see the /api/products routes in
 * routes/api.php) — only checkout, order history, and account settings
 * require a signed-in buyer, since they touch this buyer's own data and
 * other sellers' inventory.
 */
Route::middleware(['supabase.auth', 'buyer'])->prefix('api/buyer')->name('api.buyer.')->group(function () {
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');

    // Self-service account settings for the logged-in buyer. Password
    // changes deliberately reuse the top-level /api/password/* routes
    // (email verification code flow) rather than a duplicate here — same
    // pattern as the admin account settings page.
    Route::get('/profile', [BuyerProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [BuyerProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [BuyerProfileController::class, 'uploadAvatar'])->name('profile.avatar');
    Route::delete('/account/deactivate', [BuyerProfileController::class, 'deactivate'])
        ->middleware('throttle:5,1')
        ->name('account.deactivate');
});