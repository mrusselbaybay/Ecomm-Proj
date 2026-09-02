<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `received_by` used to be required because the only way a row was ever
 * created was a logistics staffer physically scanning the parcel in (see
 * ParcelAssignmentController::receive). Now a row is also created the
 * moment a seller confirms handover to a registered courier
 * (App\Services\ParcelIntakeService, called from
 * SellerOrderController::updateStatus) so the parcel already shows up in
 * that company's sorting queue — but nobody at the sorting center has
 * scanned it yet at that point, so there's no profile to stamp here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->uuid('received_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->uuid('received_by')->nullable(false)->change();
        });
    }
};
