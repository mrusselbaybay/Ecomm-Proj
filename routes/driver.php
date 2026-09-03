<?php

use App\Http\Controllers\Driver\DriverDeliveryController;
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

    // "Deliveries" tab (driver_deliveries_screen.dart) — parcels the
    // logistics team has assigned to this rider/courier. Listing itself is
    // read-only; "pickup" and "deliver" are the two rider-initiated,
    // photo-gated actions that walk a row from "assigned" -> "handed_off"
    // -> delivered (see DriverDeliveryController's docblock).
    Route::get('/deliveries', [DriverDeliveryController::class, 'index'])->name('deliveries.index');
    // Resolve a scanned parcel confirmation QR ("NXP:<token>") to one of
    // this rider's deliveries. Read-only lookup + 'verify' scan log; the
    // pickup/deliver actions below take the same token to log their scan.
    Route::post('/deliveries/verify-qr', [DriverDeliveryController::class, 'verifyQr'])
        ->name('deliveries.verify-qr');
    Route::post('/deliveries/{parcelAssignment}/pickup', [DriverDeliveryController::class, 'pickup'])
        ->name('deliveries.pickup');
    Route::get('/deliveries/{parcelAssignment}/pickup-photo', [DriverDeliveryController::class, 'pickupPhoto'])
        ->name('deliveries.pickup-photo');
    Route::post('/deliveries/{parcelAssignment}/deliver', [DriverDeliveryController::class, 'deliver'])
        ->name('deliveries.deliver');
    Route::get('/deliveries/{parcelAssignment}/photo', [DriverDeliveryController::class, 'photo'])
        ->name('deliveries.photo');
});
