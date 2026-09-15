<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parcel now points at the (municipality, barangay) assignment it was
 * routed against, not a named multi-municipality area — see
 * logistics_barangay_assignments. Renamed rather than reusing
 * delivery_area_id for a differently-shaped target table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->uuid('barangay_assignment_id')->nullable()->after('logistics_company_id');
            $table->index('barangay_assignment_id');
        });

        if (Schema::hasColumn('parcel_assignments', 'delivery_area_id')) {
            Schema::table('parcel_assignments', function (Blueprint $table) {
                $table->dropIndex(['delivery_area_id']);
                $table->dropColumn('delivery_area_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        if (! Schema::hasColumn('parcel_assignments', 'delivery_area_id')) {
            Schema::table('parcel_assignments', function (Blueprint $table) {
                $table->uuid('delivery_area_id')->nullable();
                $table->index('delivery_area_id');
            });
        }

        if (Schema::hasColumn('parcel_assignments', 'barangay_assignment_id')) {
            Schema::table('parcel_assignments', function (Blueprint $table) {
                $table->dropIndex(['barangay_assignment_id']);
                $table->dropColumn('barangay_assignment_id');
            });
        }
    }
};
