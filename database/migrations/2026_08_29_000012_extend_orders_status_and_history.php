<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive support for the seller's granular fulfilment flow.
 *
 *   Pending(=New) -> Confirmed -> Processing -> Packed -> Ready for Pickup
 *   -> Shipped(=In Transit) -> Delivered
 *
 * IMPORTANT — this is deliberately ADDITIVE so the buyer, logistics and
 * admin branches keep working unchanged:
 *   - 'New' stays the stored value the buyer checkout creates (the seller
 *     UI just labels it "Pending"); 'In Transit' stays what logistics
 *     sets (labelled "Shipped"). Nothing is renamed or data-migrated.
 *   - The orders.status CHECK is only WIDENED to also allow 'Confirmed',
 *     'Packed', 'Ready for Pickup' and 'Rejected'. Old values still pass.
 *
 * Also:
 *   - orders gains cancellation_reason / cancelled_by / cancelled_at.
 *   - order_status_history gains previous_status (the spec wants each
 *     history row to record the status it moved *from*).
 */
return new class extends Migration
{
    public function up(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'cancellation_reason')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->text('cancellation_reason')->nullable();
                $table->uuid('cancelled_by')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
            });
        }

        if (Schema::hasTable('order_status_history') && ! Schema::hasColumn('order_status_history', 'previous_status')) {
            Schema::table('order_status_history', function (Blueprint $table) {
                $table->string('previous_status')->nullable();
            });
        }

        if ($pg) {
            DB::statement('ALTER TABLE public.orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE public.orders ADD CONSTRAINT orders_status_check CHECK (
                    status::text = ANY (ARRAY[
                        'New', 'Confirmed', 'Processing', 'Packed', 'Ready for Pickup',
                        'In Transit', 'Delivered', 'Cancelled', 'Rejected'
                    ]::text[])
                )
            SQL);
        }
    }

    public function down(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';

        if ($pg) {
            DB::statement('ALTER TABLE public.orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement(<<<'SQL'
                ALTER TABLE public.orders ADD CONSTRAINT orders_status_check CHECK (
                    status::text = ANY (ARRAY[
                        'New', 'Processing', 'In Transit', 'Delivered', 'Cancelled'
                    ]::text[])
                )
            SQL);
        }

        if (Schema::hasTable('order_status_history') && Schema::hasColumn('order_status_history', 'previous_status')) {
            Schema::table('order_status_history', fn (Blueprint $t) => $t->dropColumn('previous_status'));
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'cancellation_reason')) {
            Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['cancellation_reason', 'cancelled_by', 'cancelled_at']));
        }
    }
};
