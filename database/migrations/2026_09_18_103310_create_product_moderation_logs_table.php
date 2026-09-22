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

        Schema::create('product_moderation_logs', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('ai_status', 30);
            $table->decimal('ai_confidence_score', 5, 4);
            $table->json('ai_flagged_signals');
            $table->text('ai_reasoning');
            $table->string('final_status', 40);
            $table->boolean('needs_human_review');
            $table->timestampsTz();

            $table->index(['product_id', 'created_at']);
            $table->index(['needs_human_review', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_moderation_logs');
    }
};
