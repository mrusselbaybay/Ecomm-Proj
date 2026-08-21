<?php

use App\Http\Controllers\Admin\AccountRegistrationController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\ComplaintController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SellerComplianceController;
use App\Http\Controllers\Admin\StaffAccountController;
use App\Http\Controllers\Admin\UserAccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Logistics\LogisticsNotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PsgcProxyController;
use App\Mail\RegistrationApproved;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ============================================================
// AUTH ROUTES
// ============================================================
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

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
Route::middleware(['supabase.auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('dashboard.stats');
        Route::get('/dashboard/notifications', [DashboardController::class, 'notifications'])->name('dashboard.notifications');

        Route::get('/registrations', [AccountRegistrationController::class, 'index'])->name('registrations.index');
        Route::get('/registrations/{profile}', [AccountRegistrationController::class, 'show'])->name('registrations.show');
        Route::post('/registrations/{profile}/approve', [AccountRegistrationController::class, 'approve'])->name('registrations.approve');
        Route::post('/registrations/{profile}/reject', [AccountRegistrationController::class, 'reject'])->name('registrations.reject');
        Route::post('/documents/{document}/review', [AccountRegistrationController::class, 'reviewDocument'])->name('documents.review');

        Route::get('/accounts', [UserAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{profile}', [UserAccountController::class, 'show'])->name('accounts.show');
        Route::put('/accounts/{profile}/status', [UserAccountController::class, 'updateStatus'])->name('accounts.update-status');

        Route::post('/staff', [StaffAccountController::class, 'store'])->name('staff.store');

        Route::get('/compliance/products', [SellerComplianceController::class, 'index'])->name('compliance.products.index');
        Route::post('/compliance/products/{product}/actions', [SellerComplianceController::class, 'store'])->name('compliance.products.actions.store');

        Route::get('/complaints', [ComplaintController::class, 'index'])->name('complaints.index');
        Route::get('/complaints/{complaint}', [ComplaintController::class, 'show'])->name('complaints.show');
        Route::put('/complaints/{complaint}', [ComplaintController::class, 'update'])->name('complaints.update');

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
