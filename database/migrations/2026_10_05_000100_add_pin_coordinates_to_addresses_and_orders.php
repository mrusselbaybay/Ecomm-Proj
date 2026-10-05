<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinpointed map location for every address a parcel can stop at:
 *
 *  - buyer_addresses       → buyer's delivery pin (per saved address)
 *  - addresses             → seller pickup pin (owner_kind='profile') and
 *                            logistics hub pin (owner_kind='logistics_company')
 *  - orders.shipping_* / pickup_*  → snapshot taken at checkout, so editing
 *                            an address later never moves an old order.
 *
 * All nullable: accounts without a pin fall back to the town-level
 * centroid in App\Support\PhilippineGeo.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['buyer_addresses', 'addresses'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'latitude')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->decimal('latitude', 10, 7)->nullable();
                    $t->decimal('longitude', 10, 7)->nullable();
                });
            }
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'shipping_latitude')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->decimal('shipping_latitude', 10, 7)->nullable();
                $t->decimal('shipping_longitude', 10, 7)->nullable();
                $t->decimal('pickup_latitude', 10, 7)->nullable();
                $t->decimal('pickup_longitude', 10, 7)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['buyer_addresses', 'addresses'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'latitude')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
            }
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'shipping_latitude')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn([
                'shipping_latitude', 'shipping_longitude', 'pickup_latitude', 'pickup_longitude',
            ]));
        }
    }
};
