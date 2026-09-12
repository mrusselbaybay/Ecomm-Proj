<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ticket_internal_notes')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('support_ticket_internal_notes', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('support_ticket_id');
            $table->uuid('admin_id');
            $table->text('body');
            $table->timestampsTz();

            $table->index(['support_ticket_id', 'created_at']);

            if ($driver === 'pgsql') {
                $table->foreign('support_ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
                $table->foreign('admin_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_internal_notes');
    }
};
