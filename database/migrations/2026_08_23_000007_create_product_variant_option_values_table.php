<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Join table: which option values make up one variant's combination.
 * A variant with Color:Black + Size:Large has two rows here. The
 * composite primary key doubles as the natural uniqueness guard against
 * attaching the same option value to a variant twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variant_option_values', function (Blueprint $table) {
            $table->uuid('product_variant_id');
            $table->uuid('product_option_value_id');

            $table->primary(['product_variant_id', 'product_option_value_id'], 'pvov_primary');

            $table->foreign('product_variant_id', 'pvov_variant_fk')
                ->references('id')->on('product_variants')->cascadeOnDelete();
            $table->foreign('product_option_value_id', 'pvov_value_fk')
                ->references('id')->on('product_option_values')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_option_values');
    }
};
