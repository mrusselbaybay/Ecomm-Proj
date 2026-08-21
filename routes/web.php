<?php

use App\Http\Controllers\Admin\AccountRegistrationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Logistics\LogisticsNotificationController;
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

// ---------- API Routes for Admin (AJAX calls from Vue) ----------
Route::middleware(['supabase.auth', 'admin'])
    ->prefix('api/admin')
    ->name('api.admin.')
    ->group(function () {
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
Route::prefix('api/logistics')->name('api.logistics.')->group(function () {
    Route::post('/notify-application-accepted', [LogisticsNotificationController::class, 'applicationAccepted'])
        ->name('notify.accepted');
    Route::post('/notify-application-rejected', [LogisticsNotificationController::class, 'applicationRejected'])
        ->name('notify.rejected');
});

// ---------- API Routes for Seller (Seller Order Page) ----------
require __DIR__.'/seller.php';

// ---------- Registration (server-side, service-role protected) ----------
// NOTE: Your project already has /api/signup/send-code, /api/signup/verify-code,
// and /api/signup/resend-code routes wired to PasswordResetController (referenced
// from app.js) that weren't included in the web.php you gave me. If those live in
// a different route file (e.g. routes/api.php), move this group there to match —
// otherwise this is fine to leave here.
Route::prefix('api/signup')->name('api.signup.')->group(function () {
    Route::post('/register', [AuthController::class, 'registerUser'])->name('register');
    Route::post('/register-logistics', [AuthController::class, 'registerLogistics'])->name('register-logistics');
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
