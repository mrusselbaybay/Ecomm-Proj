<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AccountRegistrationController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PickupCourierController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ---------- Public Routes ----------
Route::get('/', [AuthController::class, 'index'])->name('home');
Route::get('/login', [AuthController::class, 'index'])->name('login');
Route::get('/signup', [AuthController::class, 'index'])->name('signup');

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

// ---------- API Routes for Admin (AJAX calls from Vue) ----------
Route::prefix('api/admin')->name('api.admin.')->group(function () {
    Route::get('/registrations', [AccountRegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{profile}', [AccountRegistrationController::class, 'show'])->name('registrations.show');
    Route::post('/registrations/{profile}/approve', [AccountRegistrationController::class, 'approve'])->name('registrations.approve');
    Route::post('/registrations/{profile}/reject', [AccountRegistrationController::class, 'reject'])->name('registrations.reject');
    Route::post('/documents/{document}/review', [AccountRegistrationController::class, 'reviewDocument'])->name('documents.review');

    Route::get('/accounts', [UserAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/{profile}', [UserAccountController::class, 'show'])->name('accounts.show');
    Route::put('/accounts/{profile}/status', [UserAccountController::class, 'updateStatus'])->name('accounts.update-status');

    Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
    Route::get('/dashboard/notifications', [DashboardController::class, 'notifications'])->name('dashboard.notifications');
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
    Route::post('/notify-application-accepted', [\App\Http\Controllers\Logistics\LogisticsNotificationController::class, 'applicationAccepted'])
        ->name('notify.accepted');
    Route::post('/notify-application-rejected', [\App\Http\Controllers\Logistics\LogisticsNotificationController::class, 'applicationRejected'])
        ->name('notify.rejected');
});

// ---------- Fallback Route ----------
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');