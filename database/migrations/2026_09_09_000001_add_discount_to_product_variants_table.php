<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-variant promotional labeling — e.g. "10% off, Flash Sale" shown on
 * the seller's own product form and variant table (see the Pricing &
 * Stock reference in Inventory.vue's Product Variants section).
 *
 * Deliberately seller-facing only for now, same as products.compare_price
 * and products.promo_code: stored and displayed, but not read by
 * CheckoutService or anywhere on the buyer side. Actually reducing what a
 * buyer pays would be a separate, larger task touching cart/order pricing
 * logic — not something this migration decides unilaterally.
 *
 * Both nullable — a variant with neither set simply has no discount
 * badge. discount_type is a free string rather than an enum/CHECK
 * constraint: the product form offers a curated list (Flash Sale,
 * Clearance, Seasonal, New Customer, Bundle) plus an "Other" field to
 * type a custom one, matching the variant-option "suggested values, not
 * a hard whitelist" pattern already used for Color/Size/Flavor/etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants') || Schema::hasColumn('product_variants', 'discount_percent')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->nullable()->after('price');
            $table->string('discount_type')->nullable()->after('discount_percent');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('product_variants') && Schema::hasColumn('product_variants', 'discount_percent')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropColumn(['discount_percent', 'discount_type']);
            });
        }
    }
};
