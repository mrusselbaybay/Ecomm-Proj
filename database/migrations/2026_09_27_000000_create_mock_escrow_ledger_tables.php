<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mock escrow + double-entry ledger (see App\Services\Payments\MockPaymentService).
 * Amounts are integer centavos (PHP) — never floats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // unfunded | funded | released | refunded
            $table->string('escrow_status', 20)->default('unfunded')->after('payment_status');
        });

        Schema::create('escrow_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('type', 20); // charge | release | refund
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key')->unique();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'type']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('escrow_transaction_id');
            $table->uuid('order_id');
            // buyer | escrow | seller | origin_logistics | last_mile_logistics | leg_logistics | platform
            $table->string('account', 30);
            $table->uuid('party_id')->nullable();
            $table->unsignedBigInteger('debit_cents')->default(0);
            $table->unsignedBigInteger('credit_cents')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('escrow_transaction_id');
            $table->index(['order_id', 'account']);
            $table->index(['account', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('escrow_transactions');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('escrow_status'));
    }
};
