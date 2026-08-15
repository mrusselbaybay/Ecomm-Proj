<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PasswordResetController;

Route::post('/password/send-code', [PasswordResetController::class, 'sendCode'])
    ->middleware('throttle:3,1');

Route::post('/password/verify-code', [PasswordResetController::class, 'verifyCode'])
    ->middleware('throttle:5,1');

Route::post('/password/reset', [PasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:5,1');

Route::post('/password/resend-code', [PasswordResetController::class, 'resendCode'])
    ->middleware('throttle:3,1');