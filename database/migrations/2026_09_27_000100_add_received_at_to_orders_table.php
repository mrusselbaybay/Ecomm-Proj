<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer-side receipt confirmation (see App\Services\Payments\OrderReceiptService):
 * set when the buyer clicks "Order Received" on a courier-delivered order,
 * or by orders:auto-confirm-receipt 7 days after delivery. Releases escrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestampTz('received_at')->nullable()->after('escrow_status');
            $table->string('received_via', 10)->nullable()->after('received_at'); // buyer | auto
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['received_at', 'received_via']));
    }
};
