<?php

use App\Http\Controllers\Driver\DriverProfileController;
use Illuminate\Support\Facades\Route;

/**
 * Included from routes/api.php — NOT routes/web.php. Its only consumer is
 * the Flutter driver/courier app, a stateless Bearer-token client with no
 * browser session/XSRF cookie, so it needs the 'api' middleware group
 * (no CSRF check) rather than 'web' (see the note in web.php next to the
 * buyer/seller route includes). The api.php entry point already prefixes
 * everything here with `api/`, so this file itself only adds `driver/`.
 *
 * 'supabase.auth' -> verifies the Supabase access token sent as a Bearer
 *                    header and resolves the matching public.profiles row
 *                    onto the request (see AuthenticateSupabaseUser).
 * 'driver'        -> requires that resolved profile to be an active driver
 *                    OR courier (see EnsureUserIsDriver) — both roles share
 *                    the driver mobile app and this Settings screen.
 */
Route::middleware(['supabase.auth', 'driver'])->prefix('driver')->name('api.driver.')->group(function () {
    // Self-service Settings for the logged-in driver/courier. Password
    // changes deliberately reuse the top-level /api/password/* routes
    // (email verification code flow) rather than a duplicate here — same
    // pattern as the buyer/admin account settings pages.
    Route::get('/profile', [DriverProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [DriverProfileController::class, 'update'])->name('profile.update');
    Route::delete('/account/deactivate', [DriverProfileController::class, 'deactivate'])
        ->middleware('throttle:5,1')
        ->name('account.deactivate');
});
