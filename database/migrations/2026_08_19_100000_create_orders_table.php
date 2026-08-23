<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Orders are split per seller at checkout time (same pattern the seller
 * Inventory/Products page already assumes: one row = one seller's
 * fulfillment unit). This keeps "does this order belong to me" a single
 * indexed column check instead of a multi-seller join, and matches what
 * resources/js/seller/composables/useOrders.js already expects (one
 * `status`, one `timeline`, one `items[]` per order).
 *
 * Shipping address is snapshotted onto the order (not FK'd to
 * public.addresses) because addresses belong to a profile, not an order,
 * and the buyer's address at checkout time must not silently change if
 * they edit their profile address later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('orders', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->string('order_number')->unique();

            $table->uuid('seller_id');
            $table->uuid('buyer_profile_id');

            // Shipping snapshot
            $table->string('recipient_name');
            $table->string('recipient_contact_no')->nullable();
            $table->string('shipping_province_name')->nullable();
            $table->string('shipping_municipality_name')->nullable();
            $table->string('shipping_barangay')->nullable();
            $table->string('shipping_street')->nullable();
            $table->string('shipping_house_no')->nullable();

            // Fulfillment status the seller is allowed to manage.
            $table->string('status')->default('New');
            // New | Processing | In Transit | Delivered | Cancelled

            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('Unpaid');
            // Unpaid | Paid | Refunded

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);

            $table->string('shipping_carrier')->nullable();
            $table->string('shipping_service')->nullable();
            $table->string('tracking_number')->nullable();

            $table->timestampTz('placed_at')->useCurrent();
            $table->timestampsTz();

            $table->index('seller_id');
            $table->index('buyer_profile_id');
            $table->index('status');

            if ($driver === 'pgsql') {
                $table->foreign('seller_id')->references('id')->on('profiles');
                $table->foreign('buyer_profile_id')->references('id')->on('profiles');
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.orders ADD CONSTRAINT orders_status_check CHECK (status IN ('New','Processing','In Transit','Delivered','Cancelled'))");
            DB::statement("ALTER TABLE public.orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('Unpaid','Paid','Refunded'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
