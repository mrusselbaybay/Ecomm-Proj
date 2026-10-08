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
        Schema::create('seller_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->decimal('gross_amount', 14, 2);
            $table->decimal('withholding_tax', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->string('period', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['seller_id', 'period']);
            $table->foreign('seller_id')->references('id')->on('profiles')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_payouts');
    }
};
