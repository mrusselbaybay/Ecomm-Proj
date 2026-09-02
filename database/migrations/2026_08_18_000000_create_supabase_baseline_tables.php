<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materializes the Supabase-native tables the buyer backend reads/writes
 * (profiles, seller_details, products, orders, order_items,
 * order_status_history) so a fresh database — i.e. the sqlite :memory:
 * test database — can run the migration set and the feature suite.
 *
 * In production these tables live in the Supabase/pgsql database and are
 * NOT Laravel-migrated (see 2026_08_23_000000's note). Every block here
 * is guarded with Schema::hasTable(), so against the real database this
 * migration is a complete no-op — it just gets recorded as "ran".
 *
 * Dated before 2026_08_19/2026_08_23 so `orders`/`order_items` exist
 * before 2026_08_23_000008 alters order_items. Columns kept minimal:
 * only what the models, controllers and tests actually touch. No foreign
 * keys — this is a test-DB convenience, and the real schema owns its own
 * constraints.
 *
 * MERGE NOTE: feature/seller has its own 2026_08_19_100000_create_orders_table
 * (and _order_items / _order_status_history) with no hasTable guard. When
 * the branches merge, add the same `if (Schema::hasTable(...)) return;`
 * guard to those three, or drop this file and keep theirs — either way
 * both branches' fresh migrate should end up guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('profiles')) {
            Schema::create('profiles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('role')->default('buyer');
                $table->string('status')->default('pending');
                $table->string('account_status')->default('pending');
                $table->string('first_name')->nullable();
                $table->string('last_name')->nullable();
                $table->string('middle_initial')->nullable();
                $table->string('sex')->nullable();
                $table->string('contact_no')->nullable();
                $table->date('birthday')->nullable();
                $table->string('email')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('seller_details')) {
            Schema::create('seller_details', function (Blueprint $table) {
                $table->uuid('profile_id')->primary();
                $table->string('business_name');
                $table->string('line_of_business');
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('seller_id');
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('category')->nullable();
                $table->string('sku')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('compare_price', 12, 2)->nullable();
                $table->string('promo_code')->nullable();
                $table->integer('stock')->default(0);
                $table->json('images')->nullable();
                $table->string('status')->default('active');
                $table->string('brand')->nullable();
                $table->string('condition')->nullable();
                $table->json('dimensions')->nullable();
                $table->decimal('weight', 12, 3)->nullable();
                $table->integer('low_stock_threshold')->nullable();
                $table->boolean('has_variants')->default(false);
                $table->json('specifications')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('order_number')->unique();
                $table->uuid('seller_id');
                $table->uuid('buyer_profile_id');
                $table->string('recipient_name');
                $table->string('recipient_contact_no')->nullable();
                $table->string('shipping_province_name')->nullable();
                $table->string('shipping_municipality_name')->nullable();
                $table->string('shipping_barangay')->nullable();
                $table->string('shipping_street')->nullable();
                $table->string('shipping_house_no')->nullable();
                $table->string('status')->default('New');
                $table->string('payment_method')->nullable();
                $table->string('payment_status')->default('Unpaid');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('shipping_fee', 12, 2)->default(0);
                $table->decimal('tax', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('shipping_carrier')->nullable();
                $table->string('shipping_service')->nullable();
                $table->string('tracking_number')->nullable();
                $table->timestampTz('placed_at')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id');
                $table->uuid('product_id')->nullable();
                $table->string('product_name');
                $table->string('category')->nullable();
                $table->string('sku')->nullable();
                $table->string('variant')->nullable();
                $table->decimal('unit_price', 12, 2);
                $table->integer('quantity')->default(1);
                $table->decimal('subtotal', 12, 2);
                $table->timestampsTz();
                // variant_id / variant_sku / variant_options are added by
                // 2026_08_23_000008_add_variant_columns_to_order_items_table.
            });
        }

        if (! Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('order_id');
                $table->string('status');
                $table->text('note')->nullable();
                $table->uuid('changed_by')->nullable();
                $table->timestampTz('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Never drop these on the real database — they aren't ours. Only
        // reversible on a throwaway (non-pgsql) test database.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        foreach (['order_status_history', 'order_items', 'orders', 'products', 'seller_details', 'profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
