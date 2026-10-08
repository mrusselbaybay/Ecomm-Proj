<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('breach_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('detected_at');
            $table->timestamp('reported_to_npc_at')->nullable();
            $table->unsignedBigInteger('affected_count')->default(0);
            $table->text('description');
            $table->string('status', 30)->default('investigating');
            $table->uuid('reported_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'detected_at']);
            $table->foreign('reported_by')->references('id')->on('profiles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('breach_incidents');
    }
};
