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

        Schema::create('complaint_updates', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('complaint_id');
            $table->uuid('admin_id')->nullable();
            $table->string('old_status', 40)->nullable();
            $table->string('new_status', 40);
            $table->text('notes');
            $table->boolean('is_internal')->default(false);
            $table->timestampsTz();

            $table->index(['complaint_id', 'created_at']);

            if ($driver === 'pgsql') {
                $table->foreign('complaint_id')->references('id')->on('complaints')->cascadeOnDelete();
                $table->foreign('admin_id')->references('id')->on('profiles')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaint_updates');
    }
};
