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

        Schema::create('complaints', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('complainant_id');
            $table->uuid('respondent_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->uuid('assigned_admin_id')->nullable();
            $table->string('type', 40)->default('complaint');
            $table->string('subject', 160);
            $table->text('description');
            $table->json('evidence')->default('[]');
            $table->string('status', 40)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->text('resolution')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'priority']);
            $table->index('created_at');

            if ($driver === 'pgsql') {
                $table->foreign('complainant_id')->references('id')->on('profiles');
                $table->foreign('respondent_id')->references('id')->on('profiles')->nullOnDelete();
                $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
                $table->foreign('assigned_admin_id')->references('id')->on('profiles')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
