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
        $driver = DB::connection()->getDriverName();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            $table->uuid('parcel_assignment_id')->nullable()->after('product_id');
            $table->uuid('logistics_company_id')->nullable()->after('parcel_assignment_id');
            $table->unsignedInteger('logistics_unread_count')->default(0);
            $table->index('parcel_assignment_id');
            $table->index(['logistics_company_id', 'last_message_at']);

            if ($driver === 'pgsql') {
                $table->foreign('parcel_assignment_id')->references('id')->on('parcel_assignments')->nullOnDelete();
                $table->foreign('logistics_company_id')->references('id')->on('logistics_companies')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->dropForeign(['parcel_assignment_id']);
                $table->dropForeign(['logistics_company_id']);
            }

            $table->dropIndex(['logistics_company_id', 'last_message_at']);
            $table->dropIndex(['parcel_assignment_id']);
            $table->dropColumn(['parcel_assignment_id', 'logistics_company_id', 'logistics_unread_count']);
        });
    }
};
