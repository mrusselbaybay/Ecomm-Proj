<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Logistics\LogisticsNotificationController;
use App\Http\Controllers\PickupCourierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ---------- Public Routes ----------
Route::get('/', [AuthController::class, 'index'])->name('home');
Route::get('/login', [AuthController::class, 'index'])->name('login');
Route::get('/signup', [AuthController::class, 'index'])->name('signup');

// ---------- Google Sign-In (Socialite handshake -> Supabase session) ----------
// Socialite only talks to Google here; handleGoogleCallback() exchanges the
// resulting identity for a real Supabase session so the rest of the app
// (profiles, RLS, role-based redirects) keeps working exactly as it does
// for email/password login. See AuthController for details.
Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.google.callback');

// ---------- Pickup Courier SPA ----------
Route::prefix('pickup-courier')->name('pickup_courier.')->group(function () {
    // Main SPA route - serves the Vue app
    Route::get('/', function () {
        return view('pickup_courier.index');
    })->name('index');
    
    // API routes for the Vue app (AJAX calls)
    Route::get('/companies', [PickupCourierController::class, 'getCompanies'])->name('companies');
    Route::get('/applications/{application}/resume', [PickupCourierController::class, 'viewResume'])->name('applications.resume');
    Route::post('/applications/{company}/apply', [PickupCourierController::class, 'apply'])->name('apply.submit');
    Route::post('/applications/{application}/withdraw', [PickupCourierController::class, 'withdraw'])->name('applications.withdraw');
});

// ---------- Admin SPA ----------
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/{any?}', function () {
        return view('admin');
    })->where('any', '.*')->name('dashboard');
});

// ---------- Logistics SPA ----------
Route::prefix('logistics')->name('logistics.')->group(function () {
    Route::get('/{any?}', function () {
        return view('logistics.dashboard');
    })->where('any', '.*')->name('dashboard');
});

// ---------- Seller SPA ----------
Route::prefix('seller')->name('seller.')->group(function () {
    Route::get('/{any?}', function () {
        return view('seller');
    })->where('any', '.*')->name('dashboard');
});

// ---------- API Routes for Logistics ----------
// NOTE: GET /api/logistics/applications is intentionally NOT registered here.
// It lives in routes/api.php (Api\Logistics\LogisticsApplicationController),
// which authenticates the Supabase bearer token the logistics dashboard sends.
// A duplicate registration used to exist here pointing at a controller that
// checked Laravel's session-based Auth::id() instead - since this app never
// establishes a Laravel session (auth is Supabase-only), that handler always
// resolved to "no company found" and silently swallowed every application.
// Because both routes shared the exact URI + method, only one could ever win;
// keep this endpoint defined in a single place (api.php) to avoid a repeat.
Route::prefix('api/logistics')->name('api.logistics.')->group(function () {
    Route::post('/notify-application-accepted', [LogisticsNotificationController::class, 'applicationAccepted'])
        ->name('notify.accepted');
    Route::post('/notify-application-rejected', [LogisticsNotificationController::class, 'applicationRejected'])
        ->name('notify.rejected');
});

// ---------- API Routes for Seller (Seller Order Page) ----------
$sellerRoutes = __DIR__.'/seller.php';

if (file_exists($sellerRoutes)) {
    require $sellerRoutes;
}

// ---------- API Routes for Buyer (Checkout / Orders) ----------
$buyerRoutes = __DIR__.'/buyer.php';

if (file_exists($buyerRoutes)) {
    require $buyerRoutes;
}

// NOTE: routes/driver.php (driver/courier Settings) is intentionally NOT
// required here. Buyer/seller are browser SPAs on this same origin, so the
// 'web' middleware group's CSRF check is satisfied automatically by their
// session/XSRF cookie. The driver/courier client is the Flutter mobile
// app — a stateless Bearer-token caller with no session cookie, so its
// routes are registered from routes/api.php instead (see the 'api' group,
// same as the existing api/courier/* routes the same app already calls).

// ---------- Registration (server-side, service-role protected) ----------
// NOTE: Your project already has /api/signup/send-code, /api/signup/verify-code,
// and /api/signup/resend-code routes wired to PasswordResetController (referenced
// from app.js) that weren't included in the web.php you gave me. If those live in
// a different route file (e.g. routes/api.php), move this group there to match —
// otherwise this is fine to leave here.
Route::prefix('api/signup')->name('api.signup.')->group(function () {
    Route::post('/register', [AuthController::class, 'registerUser'])->name('register');
    Route::post('/register-logistics', [AuthController::class, 'registerLogistics'])->name('register-logistics');
    Route::post('/complete-google', [AuthController::class, 'completeGoogleSignup'])->name('complete-google');
});
// ---------- Buyer SPA ----------
Route::prefix('buyer')->name('buyer.')->group(function () {
    Route::get('/{any?}', function () {
        return view('buyer.dashboard');
    })->where('any', '.*')->name('dashboard');
});

// ---------- Fallback Route ----------
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
