<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the 'roster' conversation type: a logistics company's own thread
 * with one of its employed couriers (see Logistics\MessageController::
 * ensureRosterConversations()), distinct from the seller-facing 'shipment'
 * type which already has seller_id/logistics_company_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            $table->uuid('courier_profile_id')->nullable()->after('logistics_company_id');
            $table->index('courier_profile_id');

            if ($driver === 'pgsql') {
                $table->foreign('courier_profile_id')->references('id')->on('profiles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->dropForeign(['courier_profile_id']);
            }

            $table->dropIndex(['courier_profile_id']);
            $table->dropColumn('courier_profile_id');
        });
    }
};
