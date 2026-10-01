<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller-funded, product-level coupons. Buyers claim them into a wallet
 * (buyer_coupons) and redeem one per order line at checkout; the seller
 * absorbs the whole discount (see PaymentSplitter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            $table->uuid('seller_id');
            $table->string('code', 32);
            $table->string('discount_type', 16); // percentage | fixed
            $table->decimal('discount_value', 12, 2);
            $table->decimal('max_discount', 12, 2)->nullable(); // percentage cap
            $table->unsignedInteger('usage_limit')->nullable(); // null = unlimited redemptions
            $table->unsignedInteger('used_count')->default(0);
            $table->timestampTz('expires_at');
            $table->string('status', 16)->default('active'); // active | expired
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
            $table->string('status', 16)->default('available'); // available | used | expired
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

        Schema::table('order_return_requests', function (Blueprint $table) {
            // Share of the line's coupon discount this request carries
            // (the discounted unit is refunded first).
            $table->decimal('coupon_discount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_return_requests', fn (Blueprint $table) => $table->dropColumn('coupon_discount'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['buyer_coupon_id', 'coupon_code', 'coupon_discount']));
        Schema::dropIfExists('buyer_coupons');
        Schema::dropIfExists('product_coupons');
    }
};
