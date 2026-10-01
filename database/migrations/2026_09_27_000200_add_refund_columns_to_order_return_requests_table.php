<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_return_requests', function (Blueprint $table) {
            // Buyer's own words when reason = 'other'.
            $table->string('other_reason')->nullable()->after('reason');
            // What the mock escrow actually refunded on approval.
            $table->decimal('refunded_amount', 12, 2)->nullable()->after('estimated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_return_requests', fn (Blueprint $table) => $table->dropColumn(['other_reason', 'refunded_amount']));
    }
};
