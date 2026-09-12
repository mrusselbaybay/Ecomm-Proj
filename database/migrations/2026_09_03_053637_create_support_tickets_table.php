<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_tickets')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('support_tickets', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->string('ticket_number')->unique();
            $table->uuid('created_by');
            $table->string('category');
            $table->string('subject', 160);
            $table->text('description');
            $table->string('priority')->default('normal');
            $table->string('status')->default('submitted');
            $table->uuid('order_id')->nullable();
            $table->uuid('assigned_admin_id')->nullable();
            $table->timestampTz('escalated_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestampsTz();

            $table->index(['created_by', 'created_at']);
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['assigned_admin_id', 'status', 'updated_at']);

            if ($driver === 'pgsql') {
                $table->foreign('created_by')->references('id')->on('profiles')->cascadeOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->foreign('assigned_admin_id')->references('id')->on('profiles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
