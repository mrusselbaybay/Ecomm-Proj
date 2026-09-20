<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "For Inventory" checkpoint (App\Models\ParcelAssignment::
 * STATUS_FOR_INVENTORY): a courier physically handing a parcel to a
 * logistics company — whether the original seller pickup, a transfer
 * courier leaving this company's own hub, or a transfer courier arriving
 * at the destination hub (see ParcelIntakeService::createTransferReceipt)
 * — no longer lands directly on a dispatch-ready status. It parks here
 * until Logistics staff scan its QR from the mobile app
 * (Api\Logistics\ParcelInventoryController::scan).
 *
 * `inventory_origin` records which of those three cases produced the row,
 * so the scan action knows whether to route it on to STATUS_HANDED_OFF
 * (pickup / transfer receipt) or STATUS_READY_TO_TRANSFER (transfer
 * departure) once scanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->timestampTz('for_inventory_at')->nullable()->after('handed_off_at');
            $table->timestampTz('inventory_scanned_at')->nullable()->after('for_inventory_at');
            $table->uuid('inventory_scanned_by')->nullable()->after('inventory_scanned_at');
            $table->string('inventory_origin', 20)->nullable()->after('inventory_scanned_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropColumn(['for_inventory_at', 'inventory_scanned_at', 'inventory_scanned_by', 'inventory_origin']);
        });
    }
};
