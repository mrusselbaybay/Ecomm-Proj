<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer's saved checkout payment methods, backing PaymentMethods.vue /
 * useBuyerPayments.js (previously localStorage only).
 *
 * SECURITY: stores only what a tokenised vault keeps client-side — card
 * brand + last 4 + holder + expiry, or a masked wallet number. The full
 * PAN and CVV are never sent to or stored by the server (the Luhn/brand
 * check stays in the browser). Wiring a real PSP token is a later job;
 * `provider_token` is reserved for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyer_payment_methods')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('buyer_payment_methods', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('buyer_profile_id');
            $table->string('type'); // card | wallet
            $table->string('brand')->nullable();        // card: Visa/Mastercard/...
            $table->string('last4', 4)->nullable();      // card
            $table->string('holder')->nullable();        // card
            $table->string('exp_month', 2)->nullable();  // card
            $table->integer('exp_year')->nullable();     // card
            $table->string('provider')->nullable();      // wallet: GCash/Maya
            $table->string('phone_masked')->nullable();  // wallet
            $table->string('label')->nullable();
            $table->string('provider_token')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index('buyer_profile_id');

            if ($driver === 'pgsql') {
                $table->foreign('buyer_profile_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.buyer_payment_methods ADD CONSTRAINT buyer_payment_methods_type_check CHECK (type IN ('card','wallet'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_payment_methods');
    }
};
