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
        Schema::create('ip_takedown_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('claimant_id')->nullable();
            $table->string('claimant_name');
            $table->string('claimant_email');
            $table->string('listing_id')->nullable();
            $table->text('work_description');
            $table->text('evidence_url')->nullable();
            $table->text('statement');
            $table->string('status', 30)->default('submitted');
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->foreign('claimant_id')->references('id')->on('profiles')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ip_takedown_requests');
    }
};
