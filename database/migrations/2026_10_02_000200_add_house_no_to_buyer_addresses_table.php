<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House / unit number as its own column, matching public.addresses, so the
 * default saved address and the account address map 1:1 (line1 = street).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyer_addresses', function (Blueprint $table) {
            $table->string('house_no', 50)->nullable()->after('contact_no');
        });
    }

    public function down(): void
    {
        Schema::table('buyer_addresses', function (Blueprint $table) {
            $table->dropColumn('house_no');
        });
    }
};
