<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_assignments', function (Blueprint $table) {
            // Set on the reverse-logistics legs of an approved Return + Refund
            // (buyer -> destination hub -> origin hub -> seller). Null = the
            // original forward delivery.
            $table->uuid('return_request_id')->nullable()->after('previous_assignment_id');
            $table->index('return_request_id');
            $table->foreign('return_request_id')->references('id')->on('order_return_requests')->nullOnDelete();
        });

        Schema::table('order_return_requests', function (Blueprint $table) {
            // When the item physically reached the seller again — the point
            // the refund is paid out and the return settled.
            $table->timestamp('returned_at')->nullable()->after('resolved_at');
            // Seller-paid return shipping (same as the original shipping fee).
            $table->decimal('return_shipping_fee', 12, 2)->nullable()->after('refunded_amount');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropForeign(['return_request_id']);
            $table->dropIndex(['return_request_id']);
            $table->dropColumn('return_request_id');
        });

        Schema::table('order_return_requests', fn (Blueprint $table) => $table->dropColumn(['returned_at', 'return_shipping_fee']));
    }
};
