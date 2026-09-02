<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parcel_assignments', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id')->unique();
            $table->uuid('logistics_company_id');
            $table->uuid('delivery_area_id')->nullable();
            $table->uuid('rider_profile_id')->nullable();
            $table->string('status', 30)->default('received');
            $table->uuid('received_by');
            $table->uuid('assigned_by')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('scanned_at')->nullable();
            $table->timestampTz('sorted_at')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('handed_off_at')->nullable();
            $table->timestampsTz();

            $table->index(['logistics_company_id', 'status']);
            $table->index(['logistics_company_id', 'rider_profile_id']);
            $table->index('delivery_area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcel_assignments');
    }
};
