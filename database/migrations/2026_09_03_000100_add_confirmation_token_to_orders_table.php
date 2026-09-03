<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the per-order parcel confirmation token. It's minted the moment an
 * order is dispatched (status -> 'In Transit', see App\Models\Order::booted)
 * and encoded into the QR code the seller prints for the parcel on Prepare
 * Orders. Every checkpoint scan (courier pickup, delivery) resolves this
 * token back to the order — see App\Models\ParcelScanEvent and
 * Driver\DriverDeliveryController::verifyQr.
 *
 * Nullable: orders placed before this migration, and any order not yet
 * dispatched, simply have no token. Unique so a scan maps to exactly one
 * order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'confirmation_token')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('confirmation_token', 64)->nullable()->unique()->after('tracking_number');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'confirmation_token')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('confirmation_token');
        });
    }
};
