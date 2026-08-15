<?php

use App\Http\Controllers\Admin\AccountRegistrationController;
use App\Http\Controllers\Admin\UserAccountController;
use Illuminate\Support\Facades\Route;

/**
 * Include this file from routes/web.php, e.g.:
 *   require __DIR__.'/admin.php';
 *
 * 'auth' -> must be logged in.
 * 'admin' -> custom middleware checking $request->user()->role === 'admin'
 *            (register it in app/Http/Kernel.php as 'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class).
 */
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {

    // Account Registrations — review & approve/reject applications
    Route::get('/account-registrations', [AccountRegistrationController::class, 'index'])
        ->name('registrations.index');
    Route::get('/account-registrations/{profile}', [AccountRegistrationController::class, 'show'])
        ->name('registrations.show');
    Route::post('/account-registrations/{profile}/approve', [AccountRegistrationController::class, 'approve'])
        ->name('registrations.approve');
    Route::post('/account-registrations/{profile}/reject', [AccountRegistrationController::class, 'reject'])
        ->name('registrations.reject');
    Route::post('/documents/{document}/review', [AccountRegistrationController::class, 'reviewDocument'])
        ->name('documents.review');

    // User Accounts — view profiles, activate/suspend/deactivate
    Route::get('/user-accounts', [UserAccountController::class, 'index'])
        ->name('users.index');
    Route::get('/user-accounts/{profile}', [UserAccountController::class, 'show'])
        ->name('users.show');
    Route::post('/user-accounts/{profile}/status', [UserAccountController::class, 'updateStatus'])
        ->name('users.status');
});