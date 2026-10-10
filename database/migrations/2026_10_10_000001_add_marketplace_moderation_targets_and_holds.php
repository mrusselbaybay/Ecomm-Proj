<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->uuid('target_product_id')->nullable()->index();
            $table->uuid('target_store_id')->nullable()->index();
            $table->string('report_reason', 80)->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.complaints ADD CONSTRAINT complaints_target_product_fk FOREIGN KEY (target_product_id) REFERENCES public.products(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE public.complaints ADD CONSTRAINT complaints_target_store_fk FOREIGN KEY (target_store_id) REFERENCES public.profiles(id) ON DELETE SET NULL');
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table): void {
                if (! Schema::hasColumn('products', 'report_hold')) {
                    $table->boolean('report_hold')->default(false)->index();
                }
            });
        }

        if (Schema::hasTable('seller_details')) {
            Schema::table('seller_details', function (Blueprint $table): void {
                if (! Schema::hasColumn('seller_details', 'report_hold')) {
                    $table->boolean('report_hold')->default(false)->index();
                }
            });
        }

        Schema::create('buyer_store_blocks', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }
            $table->foreignUuid('buyer_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUuid('seller_id')->constrained('profiles')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['buyer_id', 'seller_id']);
            $table->index(['seller_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_store_blocks');

        if (Schema::hasTable('seller_details') && Schema::hasColumn('seller_details', 'report_hold')) {
            Schema::table('seller_details', fn (Blueprint $table) => $table->dropColumn('report_hold'));
        }
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'report_hold')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('report_hold'));
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.complaints DROP CONSTRAINT IF EXISTS complaints_target_product_fk');
            DB::statement('ALTER TABLE public.complaints DROP CONSTRAINT IF EXISTS complaints_target_store_fk');
        }
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropColumn(['target_product_id', 'target_store_id', 'report_reason']);
        });
    }
};
