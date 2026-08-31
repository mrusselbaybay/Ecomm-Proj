<?php

use App\Http\Controllers\Seller\CategoryConfigController;
use App\Http\Controllers\Seller\MessageController;
use App\Http\Controllers\Seller\SellerDeliveryController;
use App\Http\Controllers\Seller\SellerFeedbackController;
use App\Http\Controllers\Seller\SellerInventoryController;
use App\Http\Controllers\Seller\SellerNotificationController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\SellerProductController;
use App\Http\Controllers\Seller\SellerReportController;
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

    // Feedback & Reviews (seller-side read/respond only — see
    // SellerFeedbackController docblock for what this does and does not
    // cover yet).
    Route::get('/feedback', [SellerFeedbackController::class, 'index'])->name('feedback.index');
    Route::get('/feedback/summary', [SellerFeedbackController::class, 'summary'])->name('feedback.summary');
    Route::get('/feedback/export', [SellerFeedbackController::class, 'export'])->name('feedback.export');
    Route::put('/feedback/{id}/respond', [SellerFeedbackController::class, 'respond'])->name('feedback.respond');

    // Performance Reports (SellerReportController) — real seller-scoped
    // analytics: KPIs, revenue trend, order status breakdown, top
    // products, CSV export. See that controller's docblock for the
    // metric formulas and eligible-order-status definitions.
    Route::get('/reports/summary', [SellerReportController::class, 'summary'])->name('reports.summary');
    Route::get('/reports/revenue-trend', [SellerReportController::class, 'revenueTrend'])->name('reports.revenue-trend');
    Route::get('/reports/order-breakdown', [SellerReportController::class, 'orderBreakdown'])->name('reports.order-breakdown');
    Route::get('/reports/top-products', [SellerReportController::class, 'topProducts'])->name('reports.top-products');
    Route::get('/reports/export', [SellerReportController::class, 'export'])->name('reports.export');

    // Delivery Confirmations (SellerDeliveryController) — read/monitor
    // only; the actual "mark as delivered" action reuses the existing
    // SellerOrderController::updateStatus endpoint above (the In
    // Transit -> Delivered transition it already permits), rather than
    // duplicating status-change logic here. See that controller's
    // docblock for scope, status mapping, and what's deliberately NOT
    // supported (proof-of-delivery, buyer confirmation, returns).
    Route::get('/deliveries', [SellerDeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('/deliveries/summary', [SellerDeliveryController::class, 'summary'])->name('deliveries.summary');
    Route::get('/deliveries/export', [SellerDeliveryController::class, 'export'])->name('deliveries.export');

    // Buyer <-> seller messaging (MessageController). Implements the API
    // contract in resources/js/seller/composables/useMessaging.js, backed
    // by the shared conversations/messages tables (the buyer side is
    // Buyer\MessageController on feature/buyer). Every query is scoped to
    // the authenticated seller; another seller's conversation 404s.
    Route::prefix('messages')->name('messages.')->group(function () {
        Route::get('/unread-count', [MessageController::class, 'unreadCount'])->name('unread-count');
        Route::get('/conversations', [MessageController::class, 'conversations'])->name('conversations.index');
        Route::post('/attachments', [MessageController::class, 'uploadAttachment'])->name('attachments.store');
        Route::get('/conversations/{id}', [MessageController::class, 'showConversation'])->name('conversations.show');
        Route::get('/conversations/{id}/messages', [MessageController::class, 'messages'])->name('conversations.messages.index');
        Route::post('/conversations/{id}/messages', [MessageController::class, 'sendMessage'])->name('conversations.messages.store');
        Route::put('/conversations/{id}/read', [MessageController::class, 'markRead'])->name('conversations.read');
        Route::put('/conversations/{id}/status', [MessageController::class, 'setStatus'])->name('conversations.status');
        Route::post('/conversations/{id}/report', [MessageController::class, 'report'])->name('conversations.report');
    });

    // Scoped 404 fallback for this group only. Without this, any
    // undefined /api/seller/* path falls through to the app-wide
    // catch-all in routes/web.php, which
    // returns an HTML SPA view rather than JSON and currently 500s
    // (view('app') doesn't exist — see AuthController::index(), which
    // correctly uses 'auth.app'). That's a shared, cross-role file, so
    // rather than touch it here, this route intercepts unmatched
    // /api/seller/* requests first and returns a clean JSON 404 —
    // exactly what useMessaging.js's apiFetch() already expects and
    // handles gracefully via `backendMissing`.
    Route::any('/{any}', function () {
        return response()->json(['message' => 'Not found.'], 404);
    })->where('any', '.*')->name('fallback');
});
