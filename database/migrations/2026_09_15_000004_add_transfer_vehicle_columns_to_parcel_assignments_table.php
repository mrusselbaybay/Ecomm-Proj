<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three seller-vs-buyer boundary triggers replace the old single
 * region-mismatch check (App\Services\TransferTriggerService):
 *   - municipality differs (same province) -> requires a Car
 *   - province differs (same region)       -> requires a Van or Truck
 *   - region differs                       -> requires a Van or Truck,
 *                                              and still sets is_transfer
 *                                              (the cross-company handoff
 *                                              hint, unchanged in meaning)
 *
 * `transfer_trigger` records which boundary fired (for staff/UI context),
 * `required_vehicle_type` is what App\Services\ParcelAutoAssignService
 * filters eligible riders by. Both are computed once at parcel-row
 * creation, same lifecycle as is_transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->string('transfer_trigger', 20)->nullable()->after('is_transfer');
            $table->string('required_vehicle_type', 20)->nullable()->after('transfer_trigger');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropColumn(['transfer_trigger', 'required_vehicle_type']);
        });
    }
};
