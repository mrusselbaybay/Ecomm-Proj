<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('product_option_values', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('product_option_id');
            $table->string('value'); // e.g. "Black", "Large", "1 kg"
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['product_option_id', 'value']);
            $table->foreign('product_option_id')->references('id')->on('product_options')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};
