<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product coupons → seller vouchers (shop/product scope, discount/shipping
 * type). Status is derived on read from the stored facts; redemptions are
 * their own rows so per-user limits, budgets and cancellations are exact.
 * Existing coupons keep their ids and become product-scoped discount vouchers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_id');
            $table->string('code', 32);
            $table->string('type', 16);  // discount | shipping
            $table->string('scope', 16); // shop | product (product-level shipping later, same column)
            $table->string('discount_type', 16); // percentage | fixed
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_spend', 12, 2)->default(0);
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->unsignedInteger('usage_limit')->nullable(); // null only on migrated coupons
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->decimal('budget_cap', 12, 2)->nullable();   // null only on migrated coupons
            $table->decimal('budget_used', 12, 2)->default(0);
            $table->boolean('stackable')->default(true);
            $table->string('funding_source', 16)->default('seller');
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['seller_id', 'code']);
            $table->index(['seller_id', 'type']);
            $table->index('expires_at');
        });

        Schema::create('seller_voucher_products', function (Blueprint $table) {
            $table->uuid('seller_voucher_id');
            $table->uuid('product_id');

            $table->primary(['seller_voucher_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('buyer_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('buyer_profile_id');
            $table->uuid('seller_voucher_id');
            $table->timestampTz('claimed_at');
            $table->timestampsTz();

            $table->unique(['buyer_profile_id', 'seller_voucher_id']);
            $table->index('seller_voucher_id');
        });

        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('seller_voucher_id');
            $table->uuid('buyer_profile_id');
            $table->uuid('order_id');
            $table->decimal('amount', 12, 2);
            $table->timestampTz('released_at')->nullable(); // order cancelled
            $table->timestampsTz();

            $table->unique(['order_id', 'seller_voucher_id']); // idempotency
            $table->index(['seller_voucher_id', 'buyer_profile_id']);
        });

        Schema::table('orders', fn (Blueprint $table) => $table->decimal('shipping_discount', 12, 2)->default(0));
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('voucher_code', 32)->nullable();
            $table->decimal('voucher_discount', 12, 2)->default(0);
        });
        Schema::table('order_return_requests', fn (Blueprint $table) => $table->decimal('voucher_discount', 12, 2)->default(0));

        if (Schema::hasTable('product_coupons')) {
            $this->copyCoupons();
        }

        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['buyer_coupon_id', 'coupon_code', 'coupon_discount']));
        Schema::table('order_return_requests', fn (Blueprint $table) => $table->dropColumn('coupon_discount'));
        Schema::dropIfExists('buyer_coupons');
        Schema::dropIfExists('product_coupons');
    }

    private function copyCoupons(): void
    {
        DB::table('seller_vouchers')->insertUsing(
            ['id', 'seller_id', 'code', 'type', 'scope', 'discount_type', 'discount_value', 'max_discount',
                'starts_at', 'expires_at', 'usage_limit', 'used_count', 'deactivated_at', 'created_at', 'updated_at'],
            DB::table('product_coupons')->select([
                'id', 'seller_id', 'code', DB::raw("'discount'"), DB::raw("'product'"), 'discount_type', 'discount_value',
                'max_discount', 'created_at', 'expires_at', 'usage_limit', 'used_count', 'deleted_at', 'created_at', 'updated_at',
            ]),
        );

        DB::table('seller_voucher_products')->insertUsing(
            ['seller_voucher_id', 'product_id'],
            DB::table('product_coupons')->select(['id', 'product_id']),
        );

        DB::table('buyer_vouchers')->insertUsing(
            ['id', 'buyer_profile_id', 'seller_voucher_id', 'claimed_at', 'created_at', 'updated_at'],
            DB::table('buyer_coupons')->select(['id', 'buyer_profile_id', 'product_coupon_id', 'claimed_at', 'created_at', 'updated_at']),
        );

        DB::table('voucher_redemptions')->insertUsing(
            ['id', 'seller_voucher_id', 'buyer_profile_id', 'order_id', 'amount', 'created_at', 'updated_at'],
            DB::table('buyer_coupons as bc')
                ->join('order_items as oi', 'oi.id', '=', 'bc.order_item_id')
                ->where('bc.status', 'used')
                ->select(['bc.id', 'bc.product_coupon_id', 'bc.buyer_profile_id', 'oi.order_id', 'oi.coupon_discount', 'bc.used_at', 'bc.updated_at']),
        );

        DB::table('order_items')->whereNotNull('coupon_code')
            ->update(['voucher_code' => DB::raw('coupon_code'), 'voucher_discount' => DB::raw('coupon_discount')]);
        DB::table('order_return_requests')->update(['voucher_discount' => DB::raw('coupon_discount')]);
        DB::table('seller_vouchers')->update([
            'budget_used' => DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM voucher_redemptions r WHERE r.seller_voucher_id = seller_vouchers.id)'),
        ]);
    }

    /** Schema only: vouchers that didn't exist as coupons can't be mapped back. */
    public function down(): void
    {
        Schema::create('product_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('seller_id');
            $table->string('code', 32);
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('expires_at');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['seller_id', 'code']);
            $table->index(['product_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
        Schema::create('buyer_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('buyer_profile_id');
            $table->uuid('product_coupon_id');
            $table->string('status', 16)->default('available');
            $table->uuid('order_item_id')->nullable();
            $table->timestampTz('claimed_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->unique(['buyer_profile_id', 'product_coupon_id']);
            $table->index(['product_coupon_id', 'status']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->uuid('buyer_coupon_id')->nullable();
            $table->string('coupon_code', 32)->nullable();
            $table->decimal('coupon_discount', 12, 2)->default(0);
        });
        Schema::table('order_return_requests', fn (Blueprint $table) => $table->decimal('coupon_discount', 12, 2)->default(0));

        Schema::table('order_return_requests', fn (Blueprint $table) => $table->dropColumn('voucher_discount'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['voucher_code', 'voucher_discount']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('shipping_discount'));
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('buyer_vouchers');
        Schema::dropIfExists('seller_voucher_products');
        Schema::dropIfExists('seller_vouchers');
    }
};
