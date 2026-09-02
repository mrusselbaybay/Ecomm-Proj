<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A buyer's reusable checkout address book.
 *
 * Deliberately NOT the shared public.addresses table: that one is a
 * PSGC-structured onboarding address (owner_kind profile|logistics_company,
 * province_code/municipality_code/barangay all NOT NULL) with a one-per-
 * profile hasOne relation (Profile::address()). It's written by the
 * seller/logistics/courier registration flows on other branches. A
 * checkout address book is many-per-buyer with free-text lines
 * (SavedAddresses.vue collects fullName/phone/line1/city/province/
 * postalCode/label), so reusing that table would mean relaxing NOT NULL
 * constraints other roles depend on. A buyer-owned table keeps the shared
 * schema untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyer_addresses')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        Schema::create('buyer_addresses', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('buyer_profile_id');
            $table->string('recipient_name');
            $table->string('contact_no');
            $table->text('line1');
            $table->string('city');
            $table->string('province');
            $table->string('postal_code')->nullable();
            $table->string('label')->default('Home');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index('buyer_profile_id');

            if ($driver === 'pgsql') {
                $table->foreign('buyer_profile_id')->references('id')->on('profiles')->cascadeOnDelete();
            }
        });

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE public.buyer_addresses ADD CONSTRAINT buyer_addresses_label_check CHECK (label IN ('Home','Work','Other'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_addresses');
    }
};
