<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PsgcProxyController;


Route::post('/password/send-code', [PasswordResetController::class, 'sendCode'])
    ->middleware('throttle:3,1');

Route::post('/password/verify-code', [PasswordResetController::class, 'verifyCode'])
    ->middleware('throttle:5,1');

Route::post('/password/reset', [PasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

Route::post('/password/resend-code', [PasswordResetController::class, 'resendCode'])
    ->middleware('throttle:3,1');

Route::prefix('psgc')->group(function () {
    Route::get('/regions', [PsgcProxyController::class, 'regions']);
    Route::get('/provinces', [PsgcProxyController::class, 'provinces']);
    Route::get('/cities-municipalities', [PsgcProxyController::class, 'citiesMunicipalities']);
    Route::get('/barangays', [PsgcProxyController::class, 'barangays']);
});

// routes/api.php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('/notify-approval', [AdminNotificationController::class, 'notifyApproval']);
    Route::post('/notify-rejection', [AdminNotificationController::class, 'notifyRejection']);
    Route::post('/notify-status-change', [AdminNotificationController::class, 'notifyStatusChange']);
});

Route::post('/signup/send-code', [PasswordResetController::class, 'sendSignupCode']);
Route::post('/signup/verify-code', [PasswordResetController::class, 'verifySignupCode']);
Route::post('/signup/resend-code', [PasswordResetController::class, 'resendSignupCode']);