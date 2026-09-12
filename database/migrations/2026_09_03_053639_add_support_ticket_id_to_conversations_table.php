<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            $table->uuid('buyer_id')->nullable()->change();
            $table->uuid('seller_id')->nullable()->change();
            $table->uuid('support_ticket_id')->nullable()->after('product_id');
            $table->index('support_ticket_id');

            if ($driver === 'pgsql') {
                $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        DB::table('conversations')->where('type', 'support')->delete();

        Schema::table('conversations', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->dropForeign(['support_ticket_id']);
            }

            $table->dropIndex(['support_ticket_id']);
            $table->dropColumn('support_ticket_id');
            $table->uuid('buyer_id')->nullable(false)->change();
            $table->uuid('seller_id')->nullable(false)->change();
        });
    }
};
