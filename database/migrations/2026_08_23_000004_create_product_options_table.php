<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * An "option" is a variant axis a seller defines for one product, e.g.
 * "Color" or "Size" (also covers Weight/Material/Flavor/Storage/any
 * custom option name — the name itself is free text, not an enum, so
 * sellers aren't limited to a fixed list).
 *
 * position preserves the order options were added in, so the seller's
 * variant table and the buyer's option pickers render option groups in a
 * stable, predictable order (Color before Size, etc.) instead of
 * whatever order a query happens to return.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('product_options', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('product_id');
            $table->string('name'); // e.g. "Color", "Size", "Weight"
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['product_id', 'name']);

            // products isn't a Laravel-migrated table (pgsql/Supabase
            // only — see 2026_08_23_000000's note); skip the FK under
            // sqlite where it doesn't exist. product_id itself is kept
            // on every driver — only the hard constraint is conditional.
            if ($driver === 'pgsql') {
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};
