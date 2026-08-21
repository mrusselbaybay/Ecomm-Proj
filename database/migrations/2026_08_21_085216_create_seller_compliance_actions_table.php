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
        Schema::create('seller_compliance_actions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('admin_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['seller_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_compliance_actions');
    }
};
