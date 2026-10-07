<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seller vouchers → one `vouchers` table for both sources:
 *   source = seller   (seller-funded; scope shop | product)
 *   source = platform (platform-funded; scope platform | category)
 * Platform vouchers add buyer eligibility, a distribution method and an
 * admin audit trail. A platform voucher used on a multi-seller cart has
 * one redemption row per seller order sharing a checkout_id; its usage
 * counts once per checkout. Orders/items/returns carry the platform-funded
 * part separately (platform_discount) so seller payouts stay whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('seller_vouchers', 'vouchers');
        Schema::rename('seller_voucher_products', 'voucher_products');
        Schema::table('voucher_products', fn (Blueprint $t) => $t->renameColumn('seller_voucher_id', 'voucher_id'));
        Schema::table('buyer_vouchers', fn (Blueprint $t) => $t->renameColumn('seller_voucher_id', 'voucher_id'));
        Schema::table('voucher_redemptions', fn (Blueprint $t) => $t->renameColumn('seller_voucher_id', 'voucher_id'));

        Schema::table('vouchers', function (Blueprint $table) {
            $table->uuid('seller_id')->nullable()->change(); // null for platform vouchers
            $table->string('source', 16)->default('seller');
            $table->string('eligibility', 16)->default('all');    // all | new | lapsed | region
            $table->json('eligibility_meta')->nullable();          // {lapsed_days} | {regions: [...]}
            $table->string('distribution', 16)->default('claim');  // claim | auto_claim | push | auto_apply
            $table->uuid('created_by')->nullable();
            $table->uuid('deactivated_by')->nullable();

            $table->index(['source', 'type']);
        });

        Schema::create('voucher_categories', function (Blueprint $table) {
            $table->uuid('voucher_id');
            $table->string('category');

            $table->primary(['voucher_id', 'category']);
            $table->index('category');
        });

        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->uuid('checkout_id')->nullable();
            $table->index(['voucher_id', 'checkout_id']);
        });

        Schema::table('orders', fn (Blueprint $t) => $t->decimal('platform_discount', 12, 2)->default(0));
        Schema::table('order_items', fn (Blueprint $t) => $t->decimal('platform_discount', 12, 2)->default(0));
        Schema::table('order_return_requests', fn (Blueprint $t) => $t->decimal('platform_discount', 12, 2)->default(0));
    }

    public function down(): void
    {
        Schema::table('order_return_requests', fn (Blueprint $t) => $t->dropColumn('platform_discount'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('platform_discount'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('platform_discount'));
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->dropIndex(['voucher_id', 'checkout_id']);
            $table->dropColumn('checkout_id');
        });
        Schema::dropIfExists('voucher_categories');

        // Platform vouchers have no seller to fall back to.
        DB::table('vouchers')->where('source', 'platform')->delete();
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['source', 'type']);
            $table->dropColumn(['source', 'eligibility', 'eligibility_meta', 'distribution', 'created_by', 'deactivated_by']);
        });

        Schema::table('voucher_redemptions', fn (Blueprint $t) => $t->renameColumn('voucher_id', 'seller_voucher_id'));
        Schema::table('buyer_vouchers', fn (Blueprint $t) => $t->renameColumn('voucher_id', 'seller_voucher_id'));
        Schema::table('voucher_products', fn (Blueprint $t) => $t->renameColumn('voucher_id', 'seller_voucher_id'));
        Schema::rename('voucher_products', 'seller_voucher_products');
        Schema::rename('vouchers', 'seller_vouchers');
    }
};
