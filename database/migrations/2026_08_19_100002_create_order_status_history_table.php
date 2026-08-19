<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors the existing public.status_audit_log pattern (used for
 * profiles/logistics_companies) but scoped to orders, and feeds the
 * "timeline" the seller Order Details page already renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('order_status_history', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('order_id');
            $table->string('status');
            $table->text('note')->nullable();
            $table->uuid('changed_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            if ($driver === 'pgsql') {
                $table->foreign('changed_by')->references('id')->on('profiles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
