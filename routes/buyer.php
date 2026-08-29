<?php

use App\Http\Controllers\Buyer\AccountController;
use App\Http\Controllers\Buyer\AddressController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\MessageController;
use App\Http\Controllers\Buyer\OrderController;
use App\Http\Controllers\Buyer\PaymentMethodController;
use App\Http\Controllers\Buyer\ReturnController;
use App\Http\Controllers\Buyer\ReviewController;
use App\Http\Controllers\Buyer\WishlistController;
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
 * routes/api.php) — only checkout, order history, and the buyer's own
 * account collections require a signed-in buyer, since they touch this
 * buyer's own data and other sellers' inventory.
 */
Route::middleware(['supabase.auth', 'buyer'])->prefix('api/buyer')->name('api.buyer.')->group(function () {
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    // Buyer's own account/profile row (public.profiles).
    Route::get('/account', [AccountController::class, 'show'])->name('account.show');
    Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');

    Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::put('/reviews/{id}', [ReviewController::class, 'update'])->name('reviews.update');
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    // Saved delivery addresses (buyer_addresses table).
    Route::get('/addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::put('/addresses/{id}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    Route::put('/addresses/{id}/default', [AddressController::class, 'setDefault'])->name('addresses.default');

    // Wishlist (buyer_wishlist_items table).
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

    // Saved payment methods (buyer_payment_methods table).
    Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');
    Route::put('/payment-methods/{id}/primary', [PaymentMethodController::class, 'setPrimary'])->name('payment-methods.primary');

    // Return / refund requests (order_return_requests table).
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');

    // Buyer <-> seller messaging (conversations / messages tables).
    Route::get('/messages/unread-count', [MessageController::class, 'unreadCount'])->name('messages.unread-count');
    Route::get('/messages/conversations', [MessageController::class, 'conversations'])->name('messages.conversations');
    Route::post('/messages/conversations', [MessageController::class, 'startConversation'])->name('messages.conversations.start');
    Route::get('/messages/conversations/{id}', [MessageController::class, 'showConversation'])->name('messages.conversations.show');
    Route::get('/messages/conversations/{id}/messages', [MessageController::class, 'messages'])->name('messages.conversations.messages');
    Route::post('/messages/conversations/{id}/messages', [MessageController::class, 'sendMessage'])->name('messages.conversations.send');
    Route::put('/messages/conversations/{id}/read', [MessageController::class, 'markRead'])->name('messages.conversations.read');
    Route::put('/messages/conversations/{id}/status', [MessageController::class, 'setStatus'])->name('messages.conversations.status');
});
