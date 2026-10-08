<?php

use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Logistics\LogisticsNotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\PsgcProxyController;
use App\Http\Controllers\SearchSuggestionController;
use App\Http\Controllers\StoreController;
use App\Mail\RegistrationApproved;
use App\Support\CategoryFieldConfig;
use App\Support\CheckoutOptions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ============================================================
// PUBLIC PRODUCT CATALOG (buyer storefront browsing)
// ============================================================
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
// Declared before /products/{id} so "related" isn't read as an id.
Route::get('/products/related', [ProductController::class, 'related'])->name('products.related');
Route::get('/products/{id}', [ProductController::class, 'show'])->name('products.show');
// Inline (base64) product photos as cached image files (ProductImage::liteSql()).
Route::get('/products/{id}/images/{index}', [ProductImageController::class, 'show'])
    ->whereNumber('index')
    ->name('products.images.show');
Route::get('/products/{id}/reviews', [ProductController::class, 'reviews'])->name('products.reviews');

// Header search suggestions: a few products + stores (ProductSearch).
Route::get('/search/suggestions', SearchSuggestionController::class)->name('search.suggestions');

// Store directory + individual store pages (products come from /products?seller_id=)
Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
Route::get('/stores/{id}/reviews', [StoreController::class, 'reviews'])->name('stores.reviews');
Route::get('/stores/{id}', [StoreController::class, 'show'])
    ->middleware('supabase.auth:optional')
    ->name('stores.show');

// Shipping options and payment methods checkout honours (CheckoutOptions).
Route::get('/checkout/options', fn () => response()->json(['data' => CheckoutOptions::toArray()]))
    ->name('checkout.options');

// Each category's product subcategories, exactly as sellers pick them
// (CategoryFieldConfig, shared with the seller app). [] = no subcategories.
Route::get('/catalog/subcategories', fn () => response()->json([
    'data' => collect(CategoryFieldConfig::categories())
        ->mapWithKeys(fn (string $category) => [$category => CategoryFieldConfig::subcategoriesFor($category)]),
]))->name('catalog.subcategories');

// ============================================================
// PASSWORD RESET ROUTES
// ============================================================
Route::prefix('password')->name('password.')->group(function () {
    Route::post('/send-code', [PasswordResetController::class, 'sendCode'])
        ->middleware('throttle:3,1')
        ->name('send-code');

    Route::post('/verify-code', [PasswordResetController::class, 'verifyCode'])
        ->middleware('throttle:5,1')
        ->name('verify-code');

    Route::post('/reset', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:5,1')
        ->name('reset');

    Route::post('/resend-code', [PasswordResetController::class, 'resendCode'])
        ->middleware('throttle:3,1')
        ->name('resend-code');
});

// ============================================================
// SIGNUP VERIFICATION ROUTES
// ============================================================
Route::prefix('signup')->name('signup.')->group(function () {
    Route::post('/send-code', [PasswordResetController::class, 'sendSignupCode'])
        ->middleware('throttle:3,1')
        ->name('send-code');

    Route::post('/verify-code', [PasswordResetController::class, 'verifySignupCode'])
        ->middleware('throttle:5,1')
        ->name('verify-code');

    Route::post('/resend-code', [PasswordResetController::class, 'resendSignupCode'])
        ->middleware('throttle:3,1')
        ->name('resend-code');
});

// ============================================================
// PSGC API ROUTES (Philippine Standard Geographic Code)
// ============================================================
Route::prefix('psgc')->name('psgc.')->group(function () {
    Route::get('/regions', [PsgcProxyController::class, 'regions'])->name('regions');
    Route::get('/provinces', [PsgcProxyController::class, 'provinces'])->name('provinces');
    Route::get('/cities-municipalities', [PsgcProxyController::class, 'citiesMunicipalities'])->name('cities-municipalities');
    Route::get('/barangays', [PsgcProxyController::class, 'barangays'])->name('barangays');
});

// ============================================================
// ADMIN NOTIFICATION ROUTES
// ============================================================
Route::prefix('admin')->name('admin.')->group(function () {
    // Approval/Rejection notifications
    Route::post('/notify-approval', [AdminNotificationController::class, 'notifyApproval'])
        ->middleware('throttle:10,1')
        ->name('notify-approval');

    Route::post('/notify-rejection', [AdminNotificationController::class, 'notifyRejection'])
        ->middleware('throttle:10,1')
        ->name('notify-rejection');

    Route::post('/notify-status-change', [AdminNotificationController::class, 'notifyStatusChange'])
        ->middleware('throttle:10,1')
        ->name('notify-status-change');

    Route::post('/notify-account-created', [AdminNotificationController::class, 'notifyAccountCreated'])
        ->middleware('throttle:10,1')
        ->name('notify-account-created');
});

Route::prefix('logistics')->group(function () {
    Route::post('/notify-application-accepted', [LogisticsNotificationController::class, 'applicationAccepted']);
    Route::post('/notify-application-rejected', [LogisticsNotificationController::class, 'applicationRejected']);
});

// ============================================================
// HELPER: Check if email endpoints are working (Development only)
// ============================================================
if (app()->environment('local')) {
    Route::get('/test-email', function () {
        try {
            Mail::to('test@example.com')->send(new RegistrationApproved('Test User'));

            return response()->json(['message' => 'Email sent successfully!']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });
}
